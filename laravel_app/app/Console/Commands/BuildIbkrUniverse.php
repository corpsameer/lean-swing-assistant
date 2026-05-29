<?php

namespace App\Console\Commands;

use App\Models\Run;
use App\Models\Symbol;
use App\Services\IbkrHealthService;
use App\Services\WorkflowDailyFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class BuildIbkrUniverse extends Command
{
    protected $signature = 'universe:build-ibkr';

    protected $description = 'Build symbol universe from IBKR scanner results';

    public function handle(WorkflowDailyFetchService $fetchService, IbkrHealthService $ibkrHealthService): int
    {
        $run = Run::create([
            'run_type' => 'build_ibkr_universe',
            'status' => 'running',
            'started_at' => now('UTC'),
        ]);

        $health = $ibkrHealthService->check();
        if (! $health['ok']) {
            $this->error('IBKR health check failed; skipping workflow safely.');
            $this->line('IBKR health details: '.$health['message']);
            $this->finishRun($run, 'failed', [
                'message' => 'IBKR health check failed; skipping workflow safely.',
                'error_message' => $health['message'],
                'ibkr_health' => $health,
            ]);

            return self::FAILURE;
        }
        $this->line('IBKR health check passed');

        $outputPath = storage_path('app/ibkr_universe.json');
        $scriptPath = $fetchService->resolvePythonIbkrBasePath().'/scripts/build_ibkr_universe.py';
        if (! is_file($scriptPath)) {
            $message = 'IBKR universe builder script not found at: '.$scriptPath;
            $this->error($message);
            $this->finishRun($run, 'failed', [
                'message' => 'IBKR universe builder script not found.',
                'error_message' => $message,
            ]);

            return self::FAILURE;
        }

        $process = new Process([$fetchService->resolvePythonExecutable(), $scriptPath, '--output', $outputPath], base_path());
        $process->setTimeout(180.0);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()."\n".$process->getOutput());
            $this->error('Universe build failed: '.$message);
            $this->finishRun($run, 'failed', [
                'message' => 'Universe build failed.',
                'error_message' => $message,
                'exit_code' => $process->getExitCode(),
            ]);

            return self::FAILURE;
        }

        if (! is_file($outputPath)) {
            $message = 'Universe build did not produce output: '.$outputPath;
            $this->error($message);
            $this->finishRun($run, 'failed', [
                'message' => 'Universe build did not produce output.',
                'error_message' => $message,
            ]);

            return self::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->finishRun($run, 'failed', [
                'message' => 'Universe JSON invalid.',
                'error_message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Universe JSON invalid: '.$e->getMessage(), 0, $e);
        }

        $scannerCodes = is_array($payload['scanner_codes'] ?? null) ? $payload['scanner_codes'] : [];
        $symbols = is_array($payload['symbols'] ?? null) ? $payload['symbols'] : [];
        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];

        $inserted = 0;
        $updated = 0;
        $reactivated = 0;
        $seenSymbols = [];

        foreach ($symbols as $row) {
            if (! is_array($row)) {
                continue;
            }
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $seenSymbols[] = $symbol;

            $updates = [
                'company_name' => $row['name'] ?? null,
                'exchange' => $row['exchange'] ?? null,
                'sector' => 'ibkr_scanner',
                'is_active' => true,
            ];

            if (Schema::hasColumn('symbols', 'source')) {
                $updates['source'] = (string) ($row['source'] ?? 'ibkr_scanner');
            }

            if (Schema::hasColumn('symbols', 'source_type')) {
                $updates['source_type'] = (string) ($row['source_type'] ?? 'scanner');
            }

            if (Schema::hasColumn('symbols', 'last_seen_at')) {
                $updates['last_seen_at'] = now();
            }

            if (Schema::hasColumn('symbols', 'scanner_metadata')) {
                $updates['scanner_metadata'] = json_encode([
                    'scanner_code' => $row['scanner_code'] ?? null,
                    'raw' => $row,
                ], JSON_UNESCAPED_SLASHES);
            }

            $existing = Symbol::query()->where('symbol', $symbol)->first();
            if ($existing === null) {
                Symbol::query()->create(array_merge(['symbol' => $symbol], $updates));
                $inserted++;
            } else {
                $wasInactive = ! (bool) $existing->is_active;
                $existing->fill($updates);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                    if ($wasInactive && (bool) $existing->is_active) {
                        $reactivated++;
                    }
                }
            }
        }

        $unique = count(array_unique($seenSymbols));
        $this->line('Scanner codes: '.implode(', ', $scannerCodes));
        $this->line('Raw symbols returned: '.count($symbols));
        $this->line('Unique symbols: '.$unique);
        $this->line('Symbols inserted: '.$inserted);
        $this->line('Symbols updated: '.$updated);
        $this->line('Symbols reactivated: '.$reactivated);
        $this->line('Symbols deactivated: 0');
        $this->line('Missing symbols were not deactivated.');
        $this->line('Errors: '.count($errors));

        foreach ($errors as $error) {
            if (is_array($error)) {
                $this->warn(sprintf('scanner=%s error=%s', (string) ($error['scanner_code'] ?? '?'), (string) ($error['error'] ?? '?')));
            }
        }

        if ($unique === 0) {
            $this->error('Universe build returned zero symbols.');
            $this->finishRun($run, 'failed', [
                'message' => 'Universe build returned zero symbols.',
                'raw_symbols_returned' => count($symbols),
                'unique_symbols' => $unique,
                'errors' => count($errors),
            ]);

            return self::FAILURE;
        }

        $this->finishRun($run, 'completed', [
            'message' => 'IBKR universe build completed.',
            'scanner_codes' => $scannerCodes,
            'raw_symbols_returned' => count($symbols),
            'unique_symbols' => $unique,
            'inserted' => $inserted,
            'updated' => $updated,
            'reactivated' => $reactivated,
            'errors' => count($errors),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function finishRun(Run $run, string $status, array $meta): void
    {
        $run->status = $status;
        $run->completed_at = now('UTC');
        $run->meta_json = $meta;
        $run->save();
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Symbol;
use App\Services\WorkflowDailyFetchService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class BuildIbkrUniverse extends Command
{
    protected $signature = 'universe:build-ibkr';

    protected $description = 'Build active symbol universe from IBKR scanner results';

    public function handle(WorkflowDailyFetchService $fetchService): int
    {
        $outputPath = storage_path('app/ibkr_universe.json');
        $scriptPath = $fetchService->resolvePythonIbkrBasePath().'/scripts/build_ibkr_universe.py';
        if (! is_file($scriptPath)) {
            $this->error('IBKR universe builder script not found at: '.$scriptPath);

            return self::FAILURE;
        }

        $process = new Process([$fetchService->resolvePythonExecutable(), $scriptPath, '--output', $outputPath], base_path());
        $process->setTimeout(180.0);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Universe build failed: '.trim($process->getErrorOutput()."\n".$process->getOutput()));

            return self::FAILURE;
        }

        if (! is_file($outputPath)) {
            $this->error('Universe build did not produce output: '.$outputPath);

            return self::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Universe JSON invalid: '.$e->getMessage(), 0, $e);
        }

        $scannerCodes = is_array($payload['scanner_codes'] ?? null) ? $payload['scanner_codes'] : [];
        $symbols = is_array($payload['symbols'] ?? null) ? $payload['symbols'] : [];
        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];

        $inserted = 0;
        $updated = 0;
        $activeSymbols = [];

        foreach ($symbols as $row) {
            if (! is_array($row)) {
                continue;
            }
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $activeSymbols[] = $symbol;

            $existing = Symbol::query()->where('symbol', $symbol)->first();
            if ($existing === null) {
                Symbol::query()->create([
                    'symbol' => $symbol,
                    'company_name' => $row['name'] ?? null,
                    'exchange' => $row['exchange'] ?? null,
                    'sector' => 'ibkr_scanner',
                    'is_active' => true,
                ]);
                $inserted++;
            } else {
                $existing->fill([
                    'company_name' => $row['name'] ?? $existing->company_name,
                    'exchange' => $row['exchange'] ?? $existing->exchange,
                    'sector' => 'ibkr_scanner',
                    'is_active' => true,
                ]);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }
            }
        }

        if ($activeSymbols !== []) {
            Symbol::query()
                ->where('sector', 'ibkr_scanner')
                ->whereNotIn('symbol', $activeSymbols)
                ->update(['is_active' => false]);
        }

        $unique = count(array_unique($activeSymbols));
        $this->line('Scanner codes: '.implode(', ', $scannerCodes));
        $this->line('Raw symbols returned: '.count($symbols));
        $this->line('Unique symbols: '.$unique);
        $this->line('Symbols inserted: '.$inserted);
        $this->line('Symbols updated: '.$updated);
        $this->line('Errors: '.count($errors));

        foreach ($errors as $error) {
            if (is_array($error)) {
                $this->warn(sprintf('scanner=%s error=%s', (string) ($error['scanner_code'] ?? '?'), (string) ($error['error'] ?? '?')));
            }
        }

        if ($unique === 0) {
            $this->error('Universe build returned zero symbols.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

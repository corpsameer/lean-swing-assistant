<?php

namespace App\Console\Commands;

use App\Models\Run;
use App\Services\IbkrHealthService;
use App\Services\WorkflowDailyFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class WorkflowDailyRefine extends Command
{
    protected $signature = 'workflow:daily-refine {--symbols= : Optional CSV symbol override for manual debugging}';

    protected $description = 'Run the daily refine workflow (daily fetch, ingest, metrics, prompt refine)';

    public function handle(WorkflowDailyFetchService $dailyFetchService, IbkrHealthService $ibkrHealthService): int
    {
        $run = Run::create([
            'run_type' => 'workflow_daily_refine',
            'status' => 'running',
            'started_at' => now('UTC'),
            'meta_json' => [
                'message' => 'Workflow started: daily-refine',
            ],
        ]);

        $this->info('Starting workflow: daily-refine');
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

        try {
            $symbolsResolution = $dailyFetchService->resolveWorkflowSymbolsWithSource(
                $this->option('symbols') !== null ? (string) $this->option('symbols') : null
            );
            $symbols = $symbolsResolution['symbols'];
            $this->printSymbolResolution($symbolsResolution);
            $this->line('Python executable: '.$dailyFetchService->resolvePythonExecutable());
            $this->line('Python base path: '.$dailyFetchService->resolvePythonIbkrBasePath());
            $this->line('Snapshot path: '.$dailyFetchService->resolveSnapshotPath());

            $this->line('Step 1/4 started: fetch daily bars');
            $fetchResult = $dailyFetchService->fetchDailyBarsBatchedToDefaultSnapshotPath(
                $symbols,
                fn (string $level, string $message) => $this->writeFetchOutput($level, $message)
            );
            $snapshotPath = $fetchResult['snapshot_path'];
            $this->line('Step 1/4 completed: snapshot written to '.$snapshotPath);

            $successfulFetchCount = $fetchResult['valid_symbols'];
            $this->line('Step 1/4 gate: valid symbols fetched = '.$successfulFetchCount);
            if (! $fetchResult['met_min_valid_symbols']) {
                $this->warn('Stopping workflow: insufficient valid daily bars fetched.');
                $this->finishRun($run, 'failed', [
                    'message' => 'Stopping workflow: insufficient valid daily bars fetched.',
                    'valid_symbols' => $successfulFetchCount,
                    'fetch_result' => $fetchResult,
                ]);

                return self::FAILURE;
            }

            if ($fetchResult['partial']) {
                $this->warn(sprintf(
                    'Continuing with partial daily data: valid=%d failed_batches=%d failed_symbols=%d',
                    $fetchResult['valid_symbols'],
                    $fetchResult['failed_batches'],
                    $fetchResult['failed_symbols']
                ));
            }

            $this->line('Continuing workflow...');

            $ingestOutput = $this->runArtisanStep('Step 2/4', 'ingest daily snapshot', 'market:ingest-json', ['path' => $snapshotPath]);
            if ($ingestOutput === null) {
                $this->finishRun($run, 'failed', [
                    'message' => 'Ingest daily snapshot step failed.',
                    'failed_step' => 'market:ingest-json',
                ]);

                return self::FAILURE;
            }
            $ingestSuccessCount = $this->extractSummaryCount($ingestOutput, 'success count');
            if ($ingestSuccessCount <= 0) {
                $this->warn('Stopping workflow: no successful daily snapshots ingested.');
                $this->finishRun($run, 'failed', [
                    'message' => 'Stopping workflow: no successful daily snapshots ingested.',
                    'ingest_success_count' => $ingestSuccessCount,
                    'ingest_output' => $ingestOutput,
                ]);

                return self::FAILURE;
            }

            $metricsOutput = $this->runArtisanStep('Step 3/4', 'compute daily metrics', 'metrics:compute-daily');
            if ($metricsOutput === null) {
                $this->finishRun($run, 'failed', [
                    'message' => 'Compute daily metrics step failed.',
                    'failed_step' => 'metrics:compute-daily',
                ]);

                return self::FAILURE;
            }
            $metricsComputed = $this->extractSummaryCount($metricsOutput, 'metrics computed');
            if ($metricsComputed <= 0) {
                $this->warn('Stopping workflow: no daily metrics computed.');
                $this->finishRun($run, 'failed', [
                    'message' => 'Stopping workflow: no daily metrics computed.',
                    'metrics_computed' => $metricsComputed,
                    'metrics_output' => $metricsOutput,
                ]);

                return self::FAILURE;
            }

            if ($this->runArtisanStep('Step 4/4', 'run Prompt B daily refine', 'prompt:daily-refine') === null) {
                $this->finishRun($run, 'failed', [
                    'message' => 'Prompt B daily refine step failed.',
                    'failed_step' => 'prompt:daily-refine',
                ]);

                return self::FAILURE;
            }
        } catch (Throwable $throwable) {
            $this->error('Step failed: '.$throwable->getMessage());
            $this->error('Workflow failed: daily-refine');
            $this->finishRun($run, 'failed', [
                'message' => 'Workflow failed: daily-refine',
                'error_message' => $throwable->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->info('Workflow completed: daily-refine');
        $this->finishRun($run, 'completed', [
            'message' => 'Workflow completed: daily-refine',
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

    /**
     * @param  array<string,mixed>  $symbolResolution
     */
    private function printSymbolResolution(array $symbolResolution): void
    {
        $symbols = $symbolResolution['symbols'];
        $count = count($symbols);
        $source = $symbolResolution['source'] ?? 'unknown';
        $totalAvailable = (int) ($symbolResolution['total_available'] ?? $count);
        $maxSymbolsApplied = $symbolResolution['max_symbols_applied'] ?? null;

        if (str_starts_with((string) $source, 'db_recent_')) {
            preg_match('/^db_recent_(\d+)d$/', (string) $source, $matches);
            $recentDays = isset($matches[1]) ? (int) $matches[1] : null;
            if ($recentDays !== null) {
                $this->line(sprintf('Using recently seen active symbols from last %d days: %d', $recentDays, $totalAvailable));
            }
            $this->line('Using DB active universe symbols: '.$totalAvailable);
        } elseif ($source === 'db') {
            $this->line('Using DB active universe symbols: '.$totalAvailable);
        } elseif ($source === 'manual_override') {
            $this->line('Using manual --symbols override: '.$count.' symbols');
        } else {
            $this->line('Using fallback WORKFLOW_SYMBOLS: '.$count);
        }

        if ($maxSymbolsApplied !== null) {
            $this->line('Applying UNIVERSE_MAX_SYMBOLS: '.$maxSymbolsApplied);
        }

        $this->line('Total symbols resolved: '.$count);
        $this->line('Symbols preview: '.$this->formatSymbolsPreview($symbols));
    }

    /**
     * @param  array<int,string>  $symbols
     */
    private function formatSymbolsPreview(array $symbols): string
    {
        $preview = array_slice($symbols, 0, 20);
        $remaining = max(0, count($symbols) - count($preview));
        $formatted = implode(', ', $preview);

        if ($remaining > 0) {
            $formatted .= ', ... (+'.$remaining.' more)';
        }

        return $formatted;
    }

    private function writeFetchOutput(string $level, string $message): void
    {
        match ($level) {
            'warn' => $this->warn($message),
            'error' => $this->error($message),
            default => $this->line($message),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runArtisanStep(string $stepLabel, string $stepName, string $command, array $arguments = []): ?string
    {
        $this->line($stepLabel.' started: '.$stepName);

        $buffer = new BufferedOutput;
        $exitCode = Artisan::call($command, $arguments, $buffer);
        $output = $buffer->fetch();
        $this->output->write($output);

        if ($exitCode !== 0) {
            $this->error($stepLabel.' failed: '.$stepName.' (exit code '.$exitCode.')');
            $this->error('Workflow failed: daily-refine');

            return null;
        }

        $this->line($stepLabel.' completed: '.$stepName);

        return $output;
    }

    private function extractSummaryCount(string $output, string $label): int
    {
        if (preg_match('/'.preg_quote($label, '/').'\s*:\s*(-?\d+)/i', $output, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}

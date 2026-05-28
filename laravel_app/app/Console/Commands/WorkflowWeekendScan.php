<?php

namespace App\Console\Commands;

use App\Services\IbkrHealthService;
use App\Services\WorkflowDailyFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class WorkflowWeekendScan extends Command
{
    protected $signature = 'workflow:weekend-scan {--symbols= : Optional CSV symbol override for manual debugging}';

    protected $description = 'Run the full weekend discovery workflow (daily fetch, ingest, metrics, scan, prompt rank)';

    public function handle(WorkflowDailyFetchService $dailyFetchService, IbkrHealthService $ibkrHealthService): int
    {
        $this->info('Starting workflow: weekend-scan');
        $health = $ibkrHealthService->check();
        if (! $health['ok']) {
            $this->error('IBKR health check failed; skipping workflow safely.');
            $this->line('IBKR health details: '.$health['message']);

            return self::FAILURE;
        }
        $this->line('IBKR health check passed');

        try {
            $symbolResolution = $dailyFetchService->resolveWorkflowSymbolsWithSource(
                $this->option('symbols') !== null ? (string) $this->option('symbols') : null
            );
            $symbols = $symbolResolution['symbols'];
            if (str_starts_with($symbolResolution['source'], 'ibkr_db')) {
                $this->line('Using DB universe symbols: '.count($symbols).' symbols (source='.$symbolResolution['source'].')');
            } elseif ($symbolResolution['source'] === 'manual_override') {
                $this->line('Using manual --symbols override: '.count($symbols).' symbols');
            } else {
                $this->line('Using fallback WORKFLOW_SYMBOLS: '.count($symbols).' symbols');
            }
            $this->line('Symbols: '.implode(', ', $symbols));
            $this->line('Python executable: '.$dailyFetchService->resolvePythonExecutable());
            $this->line('Python base path: '.$dailyFetchService->resolvePythonIbkrBasePath());
            $this->line('Snapshot path: '.$dailyFetchService->resolveSnapshotPath());

            $this->line('Step 1/5 started: fetch daily bars');
            $snapshotPath = $dailyFetchService->fetchDailyBarsToDefaultSnapshotPath($symbols);
            $this->line('Step 1/5 completed: snapshot written to '.$snapshotPath);

            $successfulFetchCount = $dailyFetchService->countSuccessfulSymbolsFromSnapshot($snapshotPath);
            $this->line('Step 1/5 gate: successful symbols fetched = '.$successfulFetchCount);
            if ($successfulFetchCount <= 0) {
                $this->warn('Stopping workflow: no valid daily bars fetched.');

                return self::FAILURE;
            }

            $ingestOutput = $this->runArtisanStep('Step 2/5', 'ingest daily snapshot', 'market:ingest-json', ['path' => $snapshotPath]);
            if ($ingestOutput === null) {
                return self::FAILURE;
            }
            $ingestSuccessCount = $this->extractSummaryCount($ingestOutput, 'success count');
            if ($ingestSuccessCount <= 0) {
                $this->warn('Stopping workflow: no successful daily snapshots ingested.');

                return self::FAILURE;
            }

            $metricsOutput = $this->runArtisanStep('Step 3/5', 'compute daily metrics', 'metrics:compute-daily');
            if ($metricsOutput === null) {
                return self::FAILURE;
            }
            $metricsComputed = $this->extractSummaryCount($metricsOutput, 'metrics computed');
            if ($metricsComputed <= 0) {
                $this->warn('Stopping workflow: no daily metrics computed.');

                return self::FAILURE;
            }

            $scanOutput = $this->runArtisanStep('Step 4/5', 'run weekend scan', 'scan:weekend');
            if ($scanOutput === null) {
                return self::FAILURE;
            }
            $scanPassed = $this->extractSummaryCount($scanOutput, 'passed');
            if ($scanPassed <= 0) {
                $this->warn('Stopping workflow: no new weekend candidates passed filters.');

                return self::SUCCESS;
            }

            if ($this->runArtisanStep('Step 5/5', 'run Prompt A weekend rank', 'prompt:weekend-rank') === null) {
                return self::FAILURE;
            }
        } catch (Throwable $throwable) {
            $this->error('Step failed: '.$throwable->getMessage());
            $this->error('Workflow failed: weekend-scan');

            return self::FAILURE;
        }

        $this->info('Workflow completed: weekend-scan');

        return self::SUCCESS;
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
            $this->error('Workflow failed: weekend-scan');

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

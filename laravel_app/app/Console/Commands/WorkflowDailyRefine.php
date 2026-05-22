<?php

namespace App\Console\Commands;

use App\Services\WorkflowDailyFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class WorkflowDailyRefine extends Command
{
    protected $signature = 'workflow:daily-refine';

    protected $description = 'Run the daily refine workflow (daily fetch, ingest, metrics, prompt refine)';

    public function handle(WorkflowDailyFetchService $dailyFetchService): int
    {
        $this->info('Starting workflow: daily-refine');

        try {
            $symbols = $dailyFetchService->resolveWorkflowSymbols();
            $this->line('Symbols: '.implode(', ', $symbols));
            $this->line('Python executable: '.$dailyFetchService->resolvePythonExecutable());
            $this->line('Python base path: '.$dailyFetchService->resolvePythonIbkrBasePath());
            $this->line('Snapshot path: '.$dailyFetchService->resolveSnapshotPath());

            $this->line('Step 1/4 started: fetch daily bars');
            $snapshotPath = $dailyFetchService->fetchDailyBarsToDefaultSnapshotPath();
            $this->line('Step 1/4 completed: snapshot written to '.$snapshotPath);

            $successfulFetchCount = $dailyFetchService->countSuccessfulSymbolsFromSnapshot($snapshotPath);
            $this->line('Step 1/4 gate: successful symbols fetched = '.$successfulFetchCount);
            if ($successfulFetchCount <= 0) {
                $this->warn('Stopping workflow: no successful daily bars fetched.');

                return self::FAILURE;
            }

            $ingestOutput = $this->runArtisanStep('Step 2/4', 'ingest daily snapshot', 'market:ingest-json', ['path' => $snapshotPath]);
            if ($ingestOutput === null) {
                return self::FAILURE;
            }
            $ingestSuccessCount = $this->extractSummaryCount($ingestOutput, 'success count');
            if ($ingestSuccessCount <= 0) {
                $this->warn('Stopping workflow: no successful daily snapshots ingested.');

                return self::FAILURE;
            }

            $metricsOutput = $this->runArtisanStep('Step 3/4', 'compute daily metrics', 'metrics:compute-daily');
            if ($metricsOutput === null) {
                return self::FAILURE;
            }
            $metricsComputed = $this->extractSummaryCount($metricsOutput, 'metrics computed');
            if ($metricsComputed <= 0) {
                $this->warn('Stopping workflow: no daily metrics computed.');

                return self::FAILURE;
            }

            if ($this->runArtisanStep('Step 4/4', 'run Prompt B daily refine', 'prompt:daily-refine') === null) {
                return self::FAILURE;
            }
        } catch (Throwable $throwable) {
            $this->error('Step failed: '.$throwable->getMessage());
            $this->error('Workflow failed: daily-refine');

            return self::FAILURE;
        }

        $this->info('Workflow completed: daily-refine');

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

<?php

namespace App\Console\Commands;

use App\Services\WorkflowDailyFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class WorkflowWeekendScan extends Command
{
    protected $signature = 'workflow:weekend-scan';

    protected $description = 'Run the full weekend discovery workflow (daily fetch, ingest, metrics, scan, prompt rank)';

    public function handle(WorkflowDailyFetchService $dailyFetchService): int
    {
        $this->info('Starting workflow: weekend-scan');

        try {
            $this->line('Step 1/5 started: fetch daily bars');
            $snapshotPath = $dailyFetchService->fetchDailyBarsToDefaultSnapshotPath();
            $this->line('Step 1/5 completed: snapshot written to '.$snapshotPath);

            if (! $this->runArtisanStep('Step 2/5', 'ingest daily snapshot', 'market:ingest-json', ['path' => $snapshotPath])) {
                return self::FAILURE;
            }

            if (! $this->runArtisanStep('Step 3/5', 'compute daily metrics', 'metrics:compute-daily')) {
                return self::FAILURE;
            }

            if (! $this->runArtisanStep('Step 4/5', 'run weekend scan', 'scan:weekend')) {
                return self::FAILURE;
            }

            if (! $this->runArtisanStep('Step 5/5', 'run Prompt A weekend rank', 'prompt:weekend-rank')) {
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
    private function runArtisanStep(string $stepLabel, string $stepName, string $command, array $arguments = []): bool
    {
        $this->line($stepLabel.' started: '.$stepName);

        $exitCode = Artisan::call($command, $arguments, $this->output);

        if ($exitCode !== 0) {
            $this->error($stepLabel.' failed: '.$stepName.' (exit code '.$exitCode.')');
            $this->error('Workflow failed: weekend-scan');

            return false;
        }

        $this->line($stepLabel.' completed: '.$stepName);

        return true;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\WorkflowDailyFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class WorkflowDailyRefine extends Command
{
    protected $signature = 'workflow:daily-refine';

    protected $description = 'Run the daily refine workflow (daily fetch, ingest, metrics, prompt refine)';

    public function handle(WorkflowDailyFetchService $dailyFetchService): int
    {
        $this->info('Starting workflow: daily-refine');

        try {
            $this->line('Step 1/4 started: fetch daily bars');
            $snapshotPath = $dailyFetchService->fetchDailyBarsToDefaultSnapshotPath();
            $this->line('Step 1/4 completed: snapshot written to '.$snapshotPath);

            if (! $this->runArtisanStep('Step 2/4', 'ingest daily snapshot', 'market:ingest-json', ['path' => $snapshotPath])) {
                return self::FAILURE;
            }

            if (! $this->runArtisanStep('Step 3/4', 'compute daily metrics', 'metrics:compute-daily')) {
                return self::FAILURE;
            }

            if (! $this->runArtisanStep('Step 4/4', 'run Prompt B daily refine', 'prompt:daily-refine')) {
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
    private function runArtisanStep(string $stepLabel, string $stepName, string $command, array $arguments = []): bool
    {
        $this->line($stepLabel.' started: '.$stepName);

        $exitCode = Artisan::call($command, $arguments, $this->output);

        if ($exitCode !== 0) {
            $this->error($stepLabel.' failed: '.$stepName.' (exit code '.$exitCode.')');
            $this->error('Workflow failed: daily-refine');

            return false;
        }

        $this->line($stepLabel.' completed: '.$stepName);

        return true;
    }
}

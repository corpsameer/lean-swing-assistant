<?php

namespace App\Console\Commands;

use App\Services\PromptBDailyRefineService;
use Illuminate\Console\Command;
use Throwable;

class RunDailyRefinePrompt extends Command
{
    protected $signature = 'prompt:daily-refine';

    protected $description = 'Run Prompt B daily watchlist refinement and store structured output';

    public function handle(PromptBDailyRefineService $service): int
    {
        try {
            $summary = $service->run();
        } catch (Throwable $throwable) {
            $this->error('Daily refine prompt failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('Daily refine prompt completed.');
        $this->line('run id: '.$summary['run_id']);
        $this->line('candidates sent: '.$summary['candidates_sent']);
        $this->line('candidates refined: '.$summary['candidates_refined']);
        $this->line('candidates updated: '.$summary['candidates_updated']);
        $this->line('errors: '.$summary['errors']);

        return self::SUCCESS;
    }
}

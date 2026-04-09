<?php

namespace App\Console\Commands;

use App\Services\PromptAWeekendRankService;
use Illuminate\Console\Command;
use Throwable;

class RunWeekendPromptRank extends Command
{
    protected $signature = 'prompt:weekend-rank';

    protected $description = 'Run Prompt A weekend candidate ranking and store structured output';

    public function handle(PromptAWeekendRankService $service): int
    {
        try {
            $summary = $service->run();
        } catch (Throwable $throwable) {
            $this->error('Weekend prompt rank failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('Weekend prompt rank completed.');
        $this->line('run id: '.$summary['run_id']);
        $this->line('candidates sent: '.$summary['candidates_sent']);
        $this->line('candidates ranked: '.$summary['candidates_ranked']);
        $this->line('candidates updated: '.$summary['candidates_updated']);
        $this->line('errors: '.$summary['errors']);

        return self::SUCCESS;
    }
}

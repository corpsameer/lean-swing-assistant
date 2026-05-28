<?php

namespace App\Console\Commands;

use App\Services\PromptDTradeReviewService;
use Illuminate\Console\Command;
use Throwable;

class RunTradeReviewPrompt extends Command
{
    protected $signature = 'prompt:trade-review {--limit=10} {--force}';

    protected $description = 'Run Prompt D post-trade review for closed simulated trades';

    public function handle(PromptDTradeReviewService $service): int
    {
        try {
            $summary = $service->run((int) $this->option('limit'), (bool) $this->option('force'));
        } catch (Throwable $throwable) {
            $this->error('Prompt D trade review failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('Prompt D trade review completed.');
        $this->line('closed trades found: '.$summary['closed_trades_found']);
        $this->line('trades reviewed: '.$summary['trades_reviewed']);
        $this->line('trades skipped because already reviewed: '.$summary['trades_skipped_already_reviewed']);
        $this->line('errors: '.$summary['errors']);

        if ($summary['closed_trades_found'] === 0) {
            $this->line('no eligible closed trades found');
        }
        if ((bool) $this->option('force')) {
            $this->line('force mode enabled: existing reviews may be updated');
        }

        return self::SUCCESS;
    }
}


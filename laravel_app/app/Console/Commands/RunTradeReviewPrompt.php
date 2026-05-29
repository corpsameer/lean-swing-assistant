<?php

namespace App\Console\Commands;

use App\Services\PromptDTradeReviewService;
use App\Support\CommandRunLogger;
use Illuminate\Console\Command;
use Throwable;

class RunTradeReviewPrompt extends Command
{
    protected $signature = 'prompt:trade-review {--limit=10} {--force}';

    protected $description = 'Run Prompt D post-trade review for closed simulated trades';

    public function handle(PromptDTradeReviewService $service, CommandRunLogger $runLogger): int
    {
        $runId = $runLogger->start('trade_review', [
            'command' => $this->signature,
        ]);

        try {
            $limit = (int) $this->option('limit');
            $force = (bool) $this->option('force');
            $summary = $service->run($limit, $force);
        } catch (Throwable $throwable) {
            $this->error('Prompt D trade review failed: '.$throwable->getMessage());
            $runLogger->fail($runId, $throwable->getMessage(), [
                'exception_class' => $throwable::class,
            ]);

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
        if ($force) {
            $this->line('force mode enabled: existing reviews may be updated');
        }

        $dbSummary = [
            'limit' => $limit,
            'trades_found' => $summary['closed_trades_found'],
            'trades_reviewed' => $summary['trades_reviewed'],
            'skipped_already_reviewed' => $summary['trades_skipped_already_reviewed'],
            'errors' => $summary['errors'],
        ];
        if ($summary['closed_trades_found'] === 0) {
            $dbSummary['message'] = 'No trades found for review.';
        }
        $runLogger->complete($runId, ['summary' => $dbSummary] + $dbSummary);

        return self::SUCCESS;
    }
}


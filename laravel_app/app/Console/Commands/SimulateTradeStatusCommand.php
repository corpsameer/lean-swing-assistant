<?php

namespace App\Console\Commands;

use App\Services\SimulatedTradeStatusService;
use App\Support\CommandRunLogger;
use App\Support\MarketWindow;
use Illuminate\Console\Command;

class SimulateTradeStatusCommand extends Command
{
    protected $signature = 'trades:simulate-status';

    protected $description = 'Update simulated trade and order statuses from latest intraday snapshot prices.';

    public function __construct(
        private readonly SimulatedTradeStatusService $service,
        private readonly CommandRunLogger $runLogger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $runId = $this->runLogger->start('simulate_status', [
            'command' => $this->signature,
        ]);

        $currentStep = 'market_window_guard';

        try {
            $windowStart = (string) config('services.market_window.simulate_status_start', '09:30');
            $windowEnd = (string) config('services.market_window.simulate_status_end', '16:10');
            $this->runLogger->step($runId, $currentStep);
            if (! MarketWindow::isWithinEtWindow($windowStart, $windowEnd)) {
                $nowEt = MarketWindow::nowEtString();
                $timezone = MarketWindow::timezone();
                $message = 'Outside simulate status window; skipped safely.';
                $this->line("Outside simulate status window. now_et={$nowEt} {$timezone}. Skipping.");
                $this->runLogger->step($runId, 'market_window_guard', 'skipped', [
                    'message' => $message,
                    'now_et' => $nowEt,
                ]);
                $this->runLogger->skip($runId, $message, [
                    'now_et' => $nowEt,
                    'window' => "{$windowStart}-{$windowEnd} {$timezone}",
                ]);

                return self::SUCCESS;
            }
            $this->runLogger->step($runId, 'market_window_guard', 'completed', ['message' => 'Within simulate status window.']);

            $currentStep = 'sync_simulated_orders';
            $this->runLogger->step($runId, $currentStep);
            $summary = $this->service->sync();
            $this->runLogger->step($runId, 'sync_simulated_orders', 'completed', [
                'orders_checked' => $summary['orders_scanned'],
                'orders_updated' => $summary['entered_count'] + $summary['tp_hit_count'] + $summary['sl_hit_count'],
            ]);
        } catch (\Throwable $throwable) {
            $this->runLogger->fail($runId, $throwable->getMessage(), [
                'exception_class' => $throwable::class,
                'failed_step' => $currentStep,
            ]);
            throw $throwable;
        }

        foreach ($summary['debug_lines'] as $line) {
            $this->line($line);
        }
        foreach ($summary['closed_lines'] as $line) {
            $this->line($line);
        }
        foreach ($summary['warning_lines'] as $line) {
            $this->warn($line);
        }

        $this->line('simulated orders scanned: '.$summary['orders_scanned']);
        $this->line('entered count: '.$summary['entered_count']);
        $this->line('tp hit count: '.$summary['tp_hit_count']);
        $this->line('sl hit count: '.$summary['sl_hit_count']);
        $this->line('skipped count: '.$summary['skipped_count']);
        $this->line('errors: '.$summary['errors']);

        $dbSummary = [
            'orders_checked' => $summary['orders_scanned'],
            'orders_updated' => $summary['entered_count'] + $summary['tp_hit_count'] + $summary['sl_hit_count'],
            'entered_count' => $summary['entered_count'],
            'closed_count' => $summary['tp_hit_count'] + $summary['sl_hit_count'],
            'tp_hit_count' => $summary['tp_hit_count'],
            'sl_hit_count' => $summary['sl_hit_count'],
            'errors' => $summary['errors'],
        ];
        if ($summary['orders_scanned'] === 0) {
            $dbSummary['message'] = 'No open simulated orders.';
        }
        $this->runLogger->complete($runId, ['summary' => $dbSummary, 'window' => "{$windowStart}-{$windowEnd} ".MarketWindow::timezone()] + $dbSummary);

        return self::SUCCESS;
    }
}

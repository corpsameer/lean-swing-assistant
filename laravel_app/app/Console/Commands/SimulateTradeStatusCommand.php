<?php

namespace App\Console\Commands;

use App\Services\SimulatedTradeStatusService;
use App\Support\MarketWindow;
use Illuminate\Console\Command;

class SimulateTradeStatusCommand extends Command
{
    protected $signature = 'trades:simulate-status';

    protected $description = 'Update simulated trade and order statuses from latest intraday snapshot prices.';

    public function __construct(private readonly SimulatedTradeStatusService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $windowStart = (string) config('services.market_window.simulate_status_start', '09:30');
        $windowEnd = (string) config('services.market_window.simulate_status_end', '16:10');

        if (! MarketWindow::isWithinEtWindow($windowStart, $windowEnd)) {
            $nowEt = MarketWindow::nowEtString();
            $timezone = MarketWindow::timezone();
            $this->line("Outside simulate status window. now_et={$nowEt} {$timezone}. Skipping.");

            return self::SUCCESS;
        }

        $summary = $this->service->sync();
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

        return self::SUCCESS;
    }
}

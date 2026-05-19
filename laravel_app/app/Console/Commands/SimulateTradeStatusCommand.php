<?php

namespace App\Console\Commands;

use App\Services\SimulatedTradeStatusService;
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
        $summary = $this->service->sync();
        foreach ($summary['debug_lines'] as $line) {
            $this->line($line);
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

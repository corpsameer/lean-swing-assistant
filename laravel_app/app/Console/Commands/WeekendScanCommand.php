<?php

namespace App\Console\Commands;

use App\Services\WeekendScanService;
use Illuminate\Console\Command;

class WeekendScanCommand extends Command
{
    protected $signature = 'scan:weekend';

    protected $description = 'Run deterministic weekend scan filters on latest derived daily metrics';

    public function handle(WeekendScanService $weekendScanService): int
    {
        $summary = $weekendScanService->run();

        $this->info('Weekend scan completed.');
        $this->line('run id: '.$summary['run_id']);
        $this->line('total scanned: '.$summary['total_scanned']);
        $this->line('passed: '.$summary['passed']);
        $this->line('rejected: '.$summary['rejected']);

        return self::SUCCESS;
    }
}

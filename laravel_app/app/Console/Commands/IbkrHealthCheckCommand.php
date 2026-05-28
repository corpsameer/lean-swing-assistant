<?php

namespace App\Console\Commands;

use App\Services\IbkrHealthService;
use Illuminate\Console\Command;

class IbkrHealthCheckCommand extends Command
{
    protected $signature = 'ibkr:health-check';

    protected $description = 'Run IBKR connectivity health check';

    public function handle(IbkrHealthService $healthService): int
    {
        $result = $healthService->check();
        if (! $result['ok']) {
            $this->error('IBKR health check failed: '.$result['message']);

            return self::FAILURE;
        }

        $this->info('IBKR health check passed');

        return self::SUCCESS;
    }
}

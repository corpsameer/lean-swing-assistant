<?php

namespace App\Console\Commands;

use App\Models\Run;
use App\Services\IbkrHealthService;
use Illuminate\Console\Command;

class IbkrHealthCheckCommand extends Command
{
    protected $signature = 'ibkr:health-check';

    protected $description = 'Run IBKR connectivity health check';

    public function handle(IbkrHealthService $healthService): int
    {
        $run = Run::create([
            'run_type' => 'ibkr_health_check',
            'status' => 'running',
            'started_at' => now('UTC'),
        ]);

        $result = $healthService->check();
        if (! $result['ok']) {
            $this->error('IBKR health check failed: '.$result['message']);
            $this->finishRun($run, 'failed', [
                'message' => 'IBKR health check failed.',
                'error_message' => $result['message'],
                'ibkr_health' => $result,
            ]);

            return self::FAILURE;
        }

        $this->info('IBKR health check passed');
        $this->finishRun($run, 'completed', [
            'message' => 'IBKR health check passed.',
            'ibkr_health' => $result,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function finishRun(Run $run, string $status, array $meta): void
    {
        $run->status = $status;
        $run->completed_at = now('UTC');
        $run->meta_json = $meta;
        $run->save();
    }
}

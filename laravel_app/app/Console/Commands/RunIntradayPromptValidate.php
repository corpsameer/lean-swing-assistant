<?php

namespace App\Console\Commands;

use App\Services\PromptCIntradayValidationService;
use Illuminate\Console\Command;
use Throwable;

class RunIntradayPromptValidate extends Command
{
    protected $signature = 'prompt:intraday-validate';

    protected $description = 'Run Prompt C intraday entry validation and create planned trade setups';

    public function handle(PromptCIntradayValidationService $service): int
    {
        try {
            $summary = $service->run();
        } catch (Throwable $throwable) {
            $this->error('Intraday validate prompt failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('Intraday validate prompt completed.');
        $this->line('run id: '.$summary['run_id']);
        $this->line('active candidates scanned: '.$summary['active_candidates_scanned']);
        $this->line('candidates sent to model: '.$summary['candidates_sent_to_model']);
        $skippedCandidates = $summary['skipped_candidates'] ?? [];
        if (is_array($skippedCandidates) && $skippedCandidates !== []) {
            foreach (array_slice($skippedCandidates, 0, 20) as $message) {
                if (is_string($message) && $message !== '') {
                    $this->line($message);
                }
            }
        }
        $this->line('enter_now count: '.$summary['enter_now_count']);
        $this->line('wait count: '.$summary['wait_count']);
        $this->line('reject count: '.$summary['reject_count']);
        $this->line('trade setups created: '.$summary['trade_setups_created']);
        $this->line('errors: '.$summary['errors']);

        return self::SUCCESS;
    }
}

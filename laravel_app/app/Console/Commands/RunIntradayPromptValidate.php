<?php

namespace App\Console\Commands;

use App\Services\IbkrHealthService;
use App\Services\IntradayRefreshService;
use App\Services\PromptCIntradayValidationService;
use Illuminate\Console\Command;
use Throwable;

class RunIntradayPromptValidate extends Command
{
    protected $signature = 'prompt:intraday-validate';

    protected $description = 'Run Prompt C intraday entry validation and create planned trade setups';

    public function handle(
        IntradayRefreshService $intradayRefreshService,
        PromptCIntradayValidationService $service,
        IbkrHealthService $ibkrHealthService
    ): int
    {
        try {
            $tradeCandidateMinScore = config('services.trade_candidate.min_score', 75);
            $tradeCandidateMinScore = is_numeric($tradeCandidateMinScore) ? (float) $tradeCandidateMinScore : 75.0;
            $this->line('Trade candidate min score: '.$this->formatScore($tradeCandidateMinScore));

            $health = $ibkrHealthService->check();
            if (! $health['ok']) {
                $this->error('IBKR health check failed; skipping workflow safely.');
                $this->line('IBKR health details: '.$health['message']);

                return self::FAILURE;
            }
            $this->line('IBKR health check passed');

            $symbols = $intradayRefreshService->resolveActiveSymbols();
            $this->line('active symbols resolved: '.count($symbols));

            if ($symbols === []) {
                $this->info('No active symbols found. Exiting cleanly.');

                return self::SUCCESS;
            }

            $this->line('fetching intraday data...');
            $this->line('symbols: '.implode(', ', $symbols));
            $outputPath = $intradayRefreshService->fetchForSymbols($symbols);

            $this->line('intraday fetch completed');
            $this->line('ingesting intraday snapshot...');
            $ingestionSummary = $intradayRefreshService->ingestFromJsonPath($outputPath);
            $this->line('intraday ingestion completed: '.$ingestionSummary['snapshots_stored'].' snapshots stored');
            $validCount = (int) ($ingestionSummary['success_count'] ?? 0);
            if ($validCount <= 0) {
                $this->warn('Skipping intraday validation: no valid intraday current prices/bars fetched.');

                return self::SUCCESS;
            }
            $this->line('continuing validation...');

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
        $this->line('skipped_score_below_threshold: '.($summary['skipped_score_below_threshold'] ?? 0));
        $this->line('skipped_missing_score: '.($summary['skipped_missing_score'] ?? 0));
        $this->line('errors: '.$summary['errors']);

        return self::SUCCESS;
    }

    private function formatScore(float $score): string
    {
        $formatted = number_format($score, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}

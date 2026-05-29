<?php

namespace App\Console\Commands;

use App\Services\IbkrHealthService;
use App\Services\IntradayRefreshService;
use App\Services\PromptCIntradayValidationService;
use App\Support\CommandRunLogger;
use App\Support\MarketWindow;
use Illuminate\Console\Command;
use Throwable;

class RunIntradayPromptValidate extends Command
{
    protected $signature = 'prompt:intraday-validate';

    protected $description = 'Run Prompt C intraday entry validation and create planned trade setups';

    public function handle(
        IntradayRefreshService $intradayRefreshService,
        PromptCIntradayValidationService $service,
        IbkrHealthService $ibkrHealthService,
        CommandRunLogger $runLogger
    ): int
    {
        $runId = $runLogger->start('intraday_validate', [
            'command' => $this->signature,
        ]);

        $currentStep = 'market_window_guard';

        try {
            $windowStart = (string) config('services.market_window.intraday_validate_start', '09:30');
            $windowEnd = (string) config('services.market_window.intraday_validate_end', '15:45');
            $runLogger->step($runId, $currentStep);
            if (! MarketWindow::isWithinEtWindow($windowStart, $windowEnd)) {
                $this->skipOutsideIntradayWindow($windowStart, $windowEnd, $runId, $runLogger);

                return self::SUCCESS;
            }
            $runLogger->step($runId, 'market_window_guard', 'completed', ['message' => 'Within intraday validation window.']);
            $currentStep = 'read_trade_candidate_config';
            $tradeCandidateMinScore = config('services.trade_candidate.min_score', 75);
            $tradeCandidateMinScore = is_numeric($tradeCandidateMinScore) ? (float) $tradeCandidateMinScore : 75.0;
            $this->line('Trade candidate min score: '.$this->formatScore($tradeCandidateMinScore));

            $currentStep = 'ibkr_health_check';
            $runLogger->step($runId, $currentStep);
            $health = $ibkrHealthService->check();
            if (! $health['ok']) {
                $this->error('IBKR health check failed; skipping workflow safely.');
                $this->line('IBKR health details: '.$health['message']);
                $runLogger->step($runId, 'ibkr_health_check', 'failed', [
                    'message' => $health['message'],
                ]);
                $runLogger->fail($runId, $health['message'], [
                    'failed_step' => 'ibkr_health_check',
                    'ibkr_health' => $health,
                ]);

                return self::FAILURE;
            }
            $this->line('IBKR health check passed');
            $runLogger->step($runId, 'ibkr_health_check', 'completed', ['message' => 'IBKR health check passed.']);

            $currentStep = 'resolve_active_symbols';
            $symbols = $intradayRefreshService->resolveActiveSymbols();
            $this->line('active symbols resolved: '.count($symbols));

            if ($symbols === []) {
                $this->info('No active symbols found. Exiting cleanly.');
                $runLogger->skip($runId, 'No active symbols found. Exiting cleanly.', [
                    'active_symbols_resolved' => 0,
                ]);

                return self::SUCCESS;
            }

            $currentStep = 'fetch_intraday_data';
            $runLogger->step($runId, $currentStep);
            $this->line('fetching intraday data...');
            $this->line('symbols: '.implode(', ', $symbols));
            $outputPath = $intradayRefreshService->fetchForSymbols($symbols);

            $runLogger->step($runId, 'fetch_intraday_data', 'completed', [
                'symbols_requested' => count($symbols),
                'output_path' => $outputPath,
            ]);
            $this->line('intraday fetch completed');
            $currentStep = 'ingest_intraday_snapshot';
            $runLogger->step($runId, $currentStep);
            $this->line('ingesting intraday snapshot...');
            $ingestionSummary = $intradayRefreshService->ingestFromJsonPath($outputPath);
            $this->line('intraday ingestion completed: '.$ingestionSummary['snapshots_stored'].' snapshots stored');
            $runLogger->step($runId, 'ingest_intraday_snapshot', 'completed', [
                'snapshots_stored' => $ingestionSummary['snapshots_stored'] ?? null,
                'success_count' => $ingestionSummary['success_count'] ?? null,
            ]);
            $validCount = (int) ($ingestionSummary['success_count'] ?? 0);
            if ($validCount <= 0) {
                $this->warn('Skipping intraday validation: no valid intraday current prices/bars fetched.');
                $runLogger->skip($runId, 'Skipping intraday validation: no valid intraday current prices/bars fetched.', [
                    'ingestion_summary' => $ingestionSummary,
                ]);

                return self::SUCCESS;
            }
            $this->line('continuing validation...');

            $currentStep = 'prompt_c_validation';
            $summary = $service->run($runId);
        } catch (Throwable $throwable) {
            $this->error('Intraday validate prompt failed: '.$throwable->getMessage());

            $runLogger->fail($runId, $throwable->getMessage(), [
                'exception_class' => $throwable::class,
                'message' => 'Intraday validate command failed.',
                'failed_step' => $currentStep,
            ]);

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

    private function skipOutsideIntradayWindow(string $windowStart, string $windowEnd, int $runId, CommandRunLogger $runLogger): void
    {
        $nowEt = MarketWindow::nowEtString();
        $timezone = MarketWindow::timezone();
        $message = 'Outside intraday validation window; skipped safely.';
        $this->line("Outside intraday validation window. now_et={$nowEt} {$timezone}. Skipping.");
        $runLogger->step($runId, 'market_window_guard', 'skipped', [
            'message' => $message,
            'now_et' => $nowEt,
        ]);
        $runLogger->skip($runId, $message, [
            'now_et' => $nowEt,
            'window' => "{$windowStart}-{$windowEnd} {$timezone}",
        ]);
    }

    private function formatScore(float $score): string
    {
        $formatted = number_format($score, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}

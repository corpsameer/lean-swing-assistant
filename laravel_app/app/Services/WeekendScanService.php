<?php

namespace App\Services;

use App\Models\MarketSnapshot;
use App\Models\Run;
use App\Models\WatchlistCandidate;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class WeekendScanService
{
    /**
     * @return array{run_id:int,total_scanned:int,passed:int,rejected:int}
     */
    public function run(): array
    {
        $run = Run::create([
            'run_type' => 'weekend_scan',
            'status' => 'running',
            'started_at' => now('UTC'),
        ]);

        $snapshots = $this->latestDerivedDailyMetricSnapshots();

        $totalScanned = 0;
        $passed = 0;

        foreach ($snapshots as $snapshot) {
            $totalScanned++;

            $metrics = Arr::get($snapshot->payload_json, 'metrics', []);
            if (! is_array($metrics) || ! $this->passesFilters($metrics)) {
                continue;
            }

            WatchlistCandidate::create([
                'run_id' => $run->id,
                'symbol_id' => $snapshot->symbol_id,
                'stage' => 'weekend',
                'status' => 'candidate',
                'setup_type' => $this->resolveSetupType($metrics),
                'score_total' => null,
                'breakout_low_price' => $this->toFloat(Arr::get($metrics, 'breakout_level')),
                'breakout_high_price' => $this->toFloat(Arr::get($metrics, 'breakout_level')),
                'support_low_price' => $this->toFloat(Arr::get($metrics, 'support_level')),
                'support_high_price' => $this->toFloat(Arr::get($metrics, 'support_level')),
                'trigger_price' => null,
                'reasoning_text' => $this->buildReasoningText($metrics),
                'prompt_output_json' => null,
                'created_at' => now('UTC'),
            ]);

            $passed++;
        }

        $rejected = $totalScanned - $passed;

        $run->status = 'completed';
        $run->completed_at = now('UTC');
        $run->meta_json = [
            'total_scanned' => $totalScanned,
            'passed' => $passed,
            'rejected' => $rejected,
        ];
        $run->save();

        return [
            'run_id' => $run->id,
            'total_scanned' => $totalScanned,
            'passed' => $passed,
            'rejected' => $rejected,
        ];
    }

    /**
     * @return Collection<int, MarketSnapshot>
     */
    private function latestDerivedDailyMetricSnapshots(): Collection
    {
        $latestBySymbol = MarketSnapshot::query()
            ->selectRaw('MAX(id) AS id')
            ->where('snapshot_type', 'derived_daily_metrics')
            ->groupBy('symbol_id');

        return MarketSnapshot::query()
            ->joinSub($latestBySymbol, 'latest', function ($join): void {
                $join->on('market_snapshots.id', '=', 'latest.id');
            })
            ->orderBy('market_snapshots.symbol_id')
            ->select('market_snapshots.*')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function passesFilters(array $metrics): bool
    {
        $lastPrice = $this->toFloat(Arr::get($metrics, 'last_price'));
        $avgVolume20d = $this->toFloat(Arr::get($metrics, 'avg_volume_20d'));
        $atrPercent = $this->toFloat(Arr::get($metrics, 'atr_percent'));
        $extensionPercent = $this->toFloat(Arr::get($metrics, 'extension_percent'));
        $distanceToBreakoutPercent = $this->toFloat(Arr::get($metrics, 'distance_to_breakout_percent'));
        $trendState = Arr::get($metrics, 'trend_state');

        if ($lastPrice === null || $lastPrice < 5.0) {
            return false;
        }

        if ($avgVolume20d === null || $avgVolume20d < 1_000_000.0) {
            return false;
        }

        if ($atrPercent === null || $atrPercent < 2.0) {
            return false;
        }

        if ($extensionPercent === null || $extensionPercent > 30.0) {
            return false;
        }

        if ($distanceToBreakoutPercent === null || $distanceToBreakoutPercent > 5.0) {
            return false;
        }

        return in_array($trendState, ['uptrend', 'neutral'], true);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function resolveSetupType(array $metrics): string
    {
        $distanceToBreakoutPercent = $this->toFloat(Arr::get($metrics, 'distance_to_breakout_percent'));

        return $distanceToBreakoutPercent !== null && $distanceToBreakoutPercent < 2.0
            ? 'breakout'
            : 'pullback';
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function buildReasoningText(array $metrics): string
    {
        return sprintf(
            'Passed weekend filters: price %.2f, avg vol %.0f, ATR%% %.2f, extension%% %.2f, dist breakout%% %.2f, trend %s.',
            $this->toFloat(Arr::get($metrics, 'last_price')) ?? 0.0,
            $this->toFloat(Arr::get($metrics, 'avg_volume_20d')) ?? 0.0,
            $this->toFloat(Arr::get($metrics, 'atr_percent')) ?? 0.0,
            $this->toFloat(Arr::get($metrics, 'extension_percent')) ?? 0.0,
            $this->toFloat(Arr::get($metrics, 'distance_to_breakout_percent')) ?? 0.0,
            (string) (Arr::get($metrics, 'trend_state') ?? 'unknown')
        );
    }

    private function toFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}

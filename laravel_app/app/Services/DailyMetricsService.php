<?php

namespace App\Services;

use InvalidArgumentException;

class DailyMetricsService
{
    /**
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<string, float|string|null>
     */
    public function compute(array $bars): array
    {
        if (count($bars) < 50) {
            throw new InvalidArgumentException('At least 50 bars are required.');
        }

        $normalizedBars = $this->normalizeBars($bars);
        $count = count($normalizedBars);

        $latest = $normalizedBars[$count - 1];
        $previous = $normalizedBars[$count - 2];

        $lastPrice = $latest['close'];
        $dailyChangePercent = $this->percentChange($previous['close'], $lastPrice);

        $latest20 = array_slice($normalizedBars, -20);
        $latest50 = array_slice($normalizedBars, -50);

        $avgVolume20d = $this->average(array_column($latest20, 'volume'));
        $atr14 = $this->computeAtr14($normalizedBars);
        $atrPercent = $this->ratioPercent($atr14, $lastPrice);

        $high20d = max(array_column($latest20, 'high'));
        $low20d = min(array_column($latest20, 'low'));
        $high50d = max(array_column($latest50, 'high'));
        $low50d = min(array_column($latest50, 'low'));

        $breakoutLevel = $high20d;
        $supportLevel = $low20d;

        $distanceToBreakoutPercent = $this->ratioPercent($breakoutLevel - $lastPrice, $lastPrice);
        $pullbackDepthPercent = $this->ratioPercent($high20d - $lastPrice, $high20d);
        $extensionPercent = $this->ratioPercent($lastPrice - $supportLevel, $supportLevel);

        $trendState = $this->classifyTrendState($lastPrice, $high50d, $low50d);

        $latestVolume = $latest['volume'];
        $relativeVolumeSimple = null;
        if ($avgVolume20d > 0) {
            $relativeVolumeSimple = $latestVolume / $avgVolume20d;
        }

        return [
            'last_price' => $this->round($lastPrice),
            'daily_change_percent' => $this->round($dailyChangePercent),
            'avg_volume_20d' => $this->round($avgVolume20d),
            'atr_14' => $this->round($atr14),
            'atr_percent' => $this->round($atrPercent),
            'high_20d' => $this->round($high20d),
            'low_20d' => $this->round($low20d),
            'high_50d' => $this->round($high50d),
            'low_50d' => $this->round($low50d),
            'breakout_level' => $this->round($breakoutLevel),
            'support_level' => $this->round($supportLevel),
            'distance_to_breakout_percent' => $this->round($distanceToBreakoutPercent),
            'pullback_depth_percent' => $this->round($pullbackDepthPercent),
            'trend_state' => $trendState,
            'extension_percent' => $this->round($extensionPercent),
            'relative_volume_simple' => $relativeVolumeSimple === null ? null : $this->round($relativeVolumeSimple),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<int, array<string, float>>
     */
    private function normalizeBars(array $bars): array
    {
        $normalized = [];

        foreach ($bars as $index => $bar) {
            foreach (['high', 'low', 'close', 'volume'] as $field) {
                if (! array_key_exists($field, $bar) || ! is_numeric($bar[$field])) {
                    throw new InvalidArgumentException("Invalid bar at index {$index}: missing numeric {$field}");
                }
            }

            $high = (float) $bar['high'];
            $low = (float) $bar['low'];
            $close = (float) $bar['close'];
            $volume = (float) $bar['volume'];

            if ($high < $low) {
                throw new InvalidArgumentException("Invalid bar at index {$index}: high is less than low");
            }

            $normalized[] = [
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, float>>  $bars
     */
    private function computeAtr14(array $bars): float
    {
        $count = count($bars);
        if ($count < 15) {
            throw new InvalidArgumentException('At least 15 bars are required for ATR(14).');
        }

        $trs = [];
        for ($i = $count - 14; $i < $count; $i++) {
            $current = $bars[$i];
            $previousClose = $bars[$i - 1]['close'];

            $tr = max(
                $current['high'] - $current['low'],
                abs($current['high'] - $previousClose),
                abs($current['low'] - $previousClose)
            );

            $trs[] = $tr;
        }

        return $this->average($trs);
    }

    private function classifyTrendState(float $lastPrice, float $high50d, float $low50d): string
    {
        $range = $high50d - $low50d;
        if ($range <= 0) {
            return 'neutral';
        }

        $midpoint = $low50d + ($range / 2);
        $distanceToHigh = abs($high50d - $lastPrice);
        $distanceToLow = abs($lastPrice - $low50d);

        if ($lastPrice >= $midpoint && $distanceToHigh < $distanceToLow) {
            return 'uptrend';
        }

        if ($lastPrice < $midpoint && $distanceToLow < $distanceToHigh) {
            return 'downtrend';
        }

        return 'neutral';
    }

    /**
     * @param  array<int, float>  $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function percentChange(float $base, float $value): float
    {
        if ($base == 0.0) {
            return 0.0;
        }

        return (($value - $base) / $base) * 100;
    }

    private function ratioPercent(float $numerator, float $denominator): float
    {
        if ($denominator == 0.0) {
            return 0.0;
        }

        return ($numerator / $denominator) * 100;
    }

    private function round(float $value): float
    {
        return round($value, 6);
    }
}

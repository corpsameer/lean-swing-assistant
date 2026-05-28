<?php

namespace App\Services;

use App\Models\MarketSnapshot;
use App\Models\Order;
use App\Models\TradeSetup;
use Illuminate\Support\Arr;

class SimulatedTradeStatusService
{
    /**
     * @return array{orders_scanned:int,entered_count:int,tp_hit_count:int,sl_hit_count:int,skipped_count:int,errors:int,debug_lines:array<int,string>,closed_lines:array<int,string>,warning_lines:array<int,string>}
     */
    public function sync(): array
    {
        $summary = [
            'orders_scanned' => 0,
            'entered_count' => 0,
            'tp_hit_count' => 0,
            'sl_hit_count' => 0,
            'skipped_count' => 0,
            'errors' => 0,
            'debug_lines' => [],
            'closed_lines' => [],
            'warning_lines' => [],
        ];

        $orders = Order::query()
            ->with(['tradeSetup.symbol', 'tradeSetup.sourceCandidate', 'symbol'])
            ->whereIn('status', ['simulated_pending', 'simulated_entered'])
            ->get();

        $orders = $orders->filter(function (Order $order): bool {
            return strtolower((string) Arr::get($order->meta_json ?? [], 'execution_driver', '')) === 'simulated';
        })->values();

        $summary['orders_scanned'] = $orders->count();

        foreach ($orders as $order) {
            try {
                $tradeSetup = $order->tradeSetup;
                $symbol = strtoupper((string) optional($order->symbol)->symbol ?: (string) optional($tradeSetup?->symbol)->symbol ?: 'UNKNOWN');
                $setupType = $this->resolveSetupType($order, $tradeSetup);
                $currentPrice = $tradeSetup ? $this->latestPriceForSymbol((int) $tradeSetup->symbol_id) : null;
                $entryPrice = $tradeSetup ? $this->resolveEntryPrice($order, $tradeSetup) : null;
                $stopPrice = $tradeSetup ? $this->resolveStopPrice($order, $tradeSetup) : null;
                $targetPrice = $tradeSetup ? $this->resolveTargetPrice($order, $tradeSetup) : null;

                if (! $tradeSetup) {
                    $summary['skipped_count']++;
                    $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, null, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'skipped: missing trade_setup');

                    continue;
                }

                if ($currentPrice === null) {
                    $summary['skipped_count']++;
                    $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'skipped: missing current_price');

                    continue;
                }

                if ($setupType === null) {
                    $summary['skipped_count']++;
                    $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, null, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'skipped: missing setup_type');

                    continue;
                }

                if ($order->status === 'simulated_pending') {
                    if ($entryPrice === null) {
                        $summary['skipped_count']++;
                        $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'skipped: missing entry_price');

                        continue;
                    }

                    $shouldEnter = $setupType === 'breakout'
                        ? $currentPrice >= $entryPrice
                        : $currentPrice <= $entryPrice;

                    if ($shouldEnter) {
                        $meta = $order->meta_json ?? [];
                        $meta['simulated_entry_price'] = $currentPrice;
                        $meta['simulated_entered_at'] = now('UTC')->toIso8601String();
                        $meta['last_simulated_price'] = $currentPrice;
                        $meta['last_simulated_checked_at'] = now('UTC')->toIso8601String();
                        $order->meta_json = $meta;
                        $order->status = 'simulated_entered';
                        $order->filled_at = now('UTC');
                        $order->save();

                        if ($tradeSetup->status !== 'entered') {
                            $tradeSetup->status = 'entered';
                            $tradeSetup->save();
                        }

                        $summary['entered_count']++;
                        $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'entered');
                    } else {
                        $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'no_change: entry condition not met');
                    }

                    continue;
                }

                if ($order->status === 'simulated_entered') {
                    if ($targetPrice === null || $stopPrice === null) {
                        $summary['skipped_count']++;
                        $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'skipped: missing stop_loss_price or take_profit_price');

                        continue;
                    }

                    if ($currentPrice <= $stopPrice) {
                        [$closedLine, $warningLine] = $this->closeTradeAs($order, $tradeSetup, $currentPrice, 'simulated_sl_hit', 'stop_loss_hit', $symbol);
                        $summary['closed_lines'][] = $closedLine;
                        if ($warningLine !== null) {
                            $summary['warning_lines'][] = $warningLine;
                        }
                        $summary['sl_hit_count']++;
                        $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'closed: stop_loss_hit');
                        continue;
                    }

                    if ($currentPrice >= $targetPrice) {
                        [$closedLine, $warningLine] = $this->closeTradeAs($order, $tradeSetup, $currentPrice, 'simulated_tp_hit', 'target1_hit', $symbol);
                        $summary['closed_lines'][] = $closedLine;
                        if ($warningLine !== null) {
                            $summary['warning_lines'][] = $warningLine;
                        }
                        $summary['tp_hit_count']++;
                        $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'closed: target1_hit');
                        continue;
                    }

                    $summary['debug_lines'][] = $this->buildDebugLine($order, $symbol, $tradeSetup, $setupType, $currentPrice, $entryPrice, $stopPrice, $targetPrice, 'no_change: exit condition not met');
                }
            } catch (\Throwable $throwable) {
                $summary['errors']++;
                $summary['debug_lines'][] = 'Order '.$order->id.' error: '.$throwable->getMessage();
            }
        }

        return $summary;
    }

    private function latestPriceForSymbol(int $symbolId): ?float
    {
        $snapshot = MarketSnapshot::query()
            ->where('symbol_id', $symbolId)
            ->where('snapshot_type', 'intraday')
            ->latest('id')
            ->first();

        if (! $snapshot) {
            return null;
        }

        $payload = $snapshot->payload_json ?? [];
        $price = $this->toFloat($this->extractInputValue($payload, [
            'metrics.current_price',
            'current_price',
            'symbol_data.current_price',
            'symbol_data.latest_price',
            'symbol_data.price',
        ]));

        return $price;
    }

    private function resolveSetupType(Order $order, ?TradeSetup $tradeSetup): ?string
    {
        $metaSetupType = strtolower((string) Arr::get($order->meta_json ?? [], 'setup_type', ''));
        if (in_array($metaSetupType, ['breakout', 'pullback'], true)) {
            return $metaSetupType;
        }

        $candidateType = strtolower((string) optional($tradeSetup?->sourceCandidate)->setup_type);

        return in_array($candidateType, ['breakout', 'pullback'], true) ? $candidateType : null;
    }

    private function resolveEntryPrice(Order $order, TradeSetup $tradeSetup): ?float
    {
        return $this->toFloat(Arr::get($order->meta_json ?? [], 'entry_price'))
            ?? $this->toFloat($tradeSetup->entry_price);
    }

    private function resolveTargetPrice(Order $order, TradeSetup $tradeSetup): ?float
    {
        return $this->toFloat(Arr::get($order->meta_json ?? [], 'take_profit_price'))
            ?? $this->toFloat(Arr::get($order->meta_json ?? [], 'bracket.take_profit'))
            ?? $this->toFloat($tradeSetup->target1_price);
    }

    private function resolveStopPrice(Order $order, TradeSetup $tradeSetup): ?float
    {
        return $this->toFloat(Arr::get($order->meta_json ?? [], 'stop_loss_price'))
            ?? $this->toFloat(Arr::get($order->meta_json ?? [], 'bracket.stop_loss'))
            ?? $this->toFloat($tradeSetup->stop_price);
    }

    private function closeTradeAs(Order $order, TradeSetup $tradeSetup, float $currentPrice, string $orderStatus, string $reason, string $symbol): array
    {
        $meta = $order->meta_json ?? [];
        $calcError = null;
        $meta['exit_reason'] = $reason;
        $meta['simulated_exit_price'] = $currentPrice;
        $meta['simulated_closed_at'] = now('UTC')->toIso8601String();
        $meta['last_simulated_checked_at'] = now('UTC')->toIso8601String();
        [$calculatedMeta, $calcError] = $this->calculateOutcomeFields($order, $tradeSetup, $meta);
        $meta = array_merge($meta, $calculatedMeta);
        if ($calcError !== null) {
            $meta['pnl_calculation_error'] = $calcError;
        }

        $order->status = $orderStatus;
        $order->meta_json = $meta;
        $order->save();

        if ($tradeSetup->status !== 'closed') {
            $tradeSetup->status = 'closed';
            $tradeSetup->save();
        }

        $closedLine = sprintf(
            'closed_trade symbol=%s order_id=%d exit_reason=%s entry_price=%s exit_price=%s pnl_percent=%s pnl_amount=%s r_multiple=%s',
            $symbol,
            $order->id,
            $reason,
            $meta['simulated_entry_price'] ?? $tradeSetup->entry_price ?? 'n/a',
            $meta['simulated_exit_price'] ?? 'n/a',
            $meta['pnl_percent'] ?? 'n/a',
            $meta['pnl_amount'] ?? 'n/a',
            $meta['r_multiple'] ?? 'n/a',
        );

        $warningLine = $calcError !== null
            ? 'warning: pnl calculation issue for order '.$order->id.' ('.$symbol.'): '.$calcError
            : null;

        return [$closedLine, $warningLine];
    }

    private function calculateOutcomeFields(Order $order, TradeSetup $tradeSetup, array $meta): array
    {
        $entryPrice = $this->toFloat(Arr::get($meta, 'simulated_entry_price'))
            ?? $this->toFloat($tradeSetup->entry_price);
        $exitPrice = $this->toFloat(Arr::get($meta, 'simulated_exit_price'));
        $stopPrice = $this->toFloat($tradeSetup->stop_price)
            ?? $this->toFloat(Arr::get($meta, 'stop_loss_price'))
            ?? $this->toFloat(Arr::get($meta, 'bracket.stop_loss'));
        $quantity = $this->toFloat($order->quantity);

        if ($entryPrice === null || $exitPrice === null || $stopPrice === null || $quantity === null || $entryPrice <= 0.0) {
            return [[], 'missing entry/exit/stop/quantity values required for pnl'];
        }

        $pnlAmount = ($exitPrice - $entryPrice) * $quantity;
        $pnlPercent = (($exitPrice - $entryPrice) / $entryPrice) * 100.0;
        $riskPerShare = $entryPrice - $stopPrice;
        $riskAmount = $riskPerShare * $quantity;
        $rewardAmount = $pnlAmount;
        $rMultiple = $riskAmount > 0.0 ? ($pnlAmount / $riskAmount) : null;

        return [[
            'pnl_amount' => round($pnlAmount, 4),
            'pnl_percent' => round($pnlPercent, 4),
            'risk_amount' => round($riskAmount, 4),
            'reward_amount' => round($rewardAmount, 4),
            'r_multiple' => $rMultiple !== null ? round($rMultiple, 4) : null,
        ], null];
    }

    private function extractInputValue(array $input, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($input, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function buildDebugLine(
        Order $order,
        string $symbol,
        ?TradeSetup $tradeSetup,
        ?string $setupType,
        ?float $currentPrice,
        ?float $entryPrice,
        ?float $stopPrice,
        ?float $targetPrice,
        string $action
    ): string {
        return sprintf(
            'Order %d %s order_status=%s trade_status=%s setup_type=%s current_price=%s entry_price=%s stop_loss_price=%s take_profit_price=%s %s',
            $order->id,
            $symbol,
            (string) $order->status,
            (string) ($tradeSetup?->status ?? 'n/a'),
            (string) ($setupType ?? 'n/a'),
            $currentPrice !== null ? (string) $currentPrice : 'n/a',
            $entryPrice !== null ? (string) $entryPrice : 'n/a',
            $stopPrice !== null ? (string) $stopPrice : 'n/a',
            $targetPrice !== null ? (string) $targetPrice : 'n/a',
            $action
        );
    }
}

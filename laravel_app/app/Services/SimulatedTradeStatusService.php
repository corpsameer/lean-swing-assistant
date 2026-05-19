<?php

namespace App\Services;

use App\Models\MarketSnapshot;
use App\Models\Order;
use App\Models\TradeSetup;
use Illuminate\Support\Arr;

class SimulatedTradeStatusService
{
    /**
     * @return array{orders_scanned:int,entered_count:int,tp_hit_count:int,sl_hit_count:int,skipped_count:int,errors:int}
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
        ];

        $orders = Order::query()
            ->with(['tradeSetup.sourceCandidate'])
            ->whereIn('status', ['simulated_pending', 'simulated_entered'])
            ->get();

        $summary['orders_scanned'] = $orders->count();

        foreach ($orders as $order) {
            try {
                $tradeSetup = $order->tradeSetup;
                if (! $tradeSetup) {
                    $summary['skipped_count']++;

                    continue;
                }

                $currentPrice = $this->latestPriceForSymbol((int) $tradeSetup->symbol_id);
                if ($currentPrice === null) {
                    $summary['skipped_count']++;

                    continue;
                }

                $setupType = $this->resolveSetupType($order, $tradeSetup);
                if ($order->status === 'simulated_pending') {
                    $entryPrice = $this->resolveEntryPrice($order, $tradeSetup);
                    if ($entryPrice === null) {
                        $summary['skipped_count']++;

                        continue;
                    }

                    $shouldEnter = $setupType === 'breakout'
                        ? $currentPrice >= $entryPrice
                        : $currentPrice <= $entryPrice;

                    if ($shouldEnter) {
                        $meta = $order->meta_json ?? [];
                        $meta['simulated_entry_price'] = $currentPrice;
                        $meta['simulated_entered_at'] = now('UTC')->toIso8601String();
                        $order->meta_json = $meta;
                        $order->status = 'simulated_entered';
                        $order->filled_at = now('UTC');
                        $order->save();

                        if ($tradeSetup->status !== 'entered') {
                            $tradeSetup->status = 'entered';
                            $tradeSetup->save();
                        }

                        $summary['entered_count']++;
                    }

                    continue;
                }

                if ($order->status === 'simulated_entered') {
                    $targetPrice = $this->resolveTargetPrice($order, $tradeSetup);
                    $stopPrice = $this->resolveStopPrice($order, $tradeSetup);
                    if ($targetPrice === null || $stopPrice === null) {
                        $summary['skipped_count']++;

                        continue;
                    }

                    if ($currentPrice >= $targetPrice) {
                        $this->closeTradeAs($order, $tradeSetup, 'simulated_tp_hit', 'target1_hit');
                        $summary['tp_hit_count']++;

                        continue;
                    }

                    if ($currentPrice <= $stopPrice) {
                        $this->closeTradeAs($order, $tradeSetup, 'simulated_sl_hit', 'stop_loss_hit');
                        $summary['sl_hit_count']++;
                    }
                }
            } catch (\Throwable) {
                $summary['errors']++;
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

        $symbolData = Arr::get($snapshot->payload_json ?? [], 'symbol_data', []);
        $price = $this->toFloat($this->extractInputValue($symbolData, ['current_price', 'last_price', 'close']));

        return $price;
    }

    private function resolveSetupType(Order $order, TradeSetup $tradeSetup): string
    {
        $metaSetupType = strtolower((string) Arr::get($order->meta_json ?? [], 'setup_type', ''));
        if (in_array($metaSetupType, ['breakout', 'pullback'], true)) {
            return $metaSetupType;
        }

        $candidateType = strtolower((string) optional($tradeSetup->sourceCandidate)->setup_type);

        return in_array($candidateType, ['breakout', 'pullback'], true) ? $candidateType : 'pullback';
    }

    private function resolveEntryPrice(Order $order, TradeSetup $tradeSetup): ?float
    {
        return $this->toFloat(Arr::get($order->meta_json ?? [], 'entry_price'))
            ?? $this->toFloat($tradeSetup->entry_price);
    }

    private function resolveTargetPrice(Order $order, TradeSetup $tradeSetup): ?float
    {
        return $this->toFloat(Arr::get($order->meta_json ?? [], 'take_profit_price'))
            ?? $this->toFloat($tradeSetup->target1_price);
    }

    private function resolveStopPrice(Order $order, TradeSetup $tradeSetup): ?float
    {
        return $this->toFloat(Arr::get($order->meta_json ?? [], 'stop_loss_price'))
            ?? $this->toFloat($tradeSetup->stop_price);
    }

    private function closeTradeAs(Order $order, TradeSetup $tradeSetup, string $orderStatus, string $reason): void
    {
        $meta = $order->meta_json ?? [];
        $meta['simulated_exit_reason'] = $reason;
        $meta['simulated_exited_at'] = now('UTC')->toIso8601String();

        $order->status = $orderStatus;
        $order->meta_json = $meta;
        $order->save();

        if ($tradeSetup->status !== 'closed') {
            $tradeSetup->status = 'closed';
            $tradeSetup->save();
        }
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
}


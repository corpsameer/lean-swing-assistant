<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TradeSetup;
use Illuminate\Support\Arr;
use RuntimeException;
use Symfony\Component\Process\Process;

class PaperTradeStatusSyncService
{
    /**
     * @return array{orders_checked:int,orders_updated:int,trade_setups_entered:int,trade_setups_cancelled:int,trade_setups_closed:int,errors:int}
     */
    public function sync(): array
    {
        $summary = [
            'orders_checked' => 0,
            'orders_updated' => 0,
            'trade_setups_entered' => 0,
            'trade_setups_cancelled' => 0,
            'trade_setups_closed' => 0,
            'errors' => 0,
        ];

        $orders = Order::query()
            ->whereNotNull('broker_order_id')
            ->whereNotIn('status', $this->terminalOrderStatuses())
            ->get();

        $summary['orders_checked'] = $orders->count();

        if ($orders->isEmpty()) {
            return $summary;
        }

        $brokerOrderIds = $orders
            ->pluck('broker_order_id')
            ->filter(fn (mixed $id): bool => trim((string) $id) !== '')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        try {
            $snapshotByBrokerOrderId = $this->fetchStatusesByBrokerOrderId($brokerOrderIds);
        } catch (RuntimeException) {
            $summary['errors']++;

            return $summary;
        }

        $changedTradeSetupIds = [];

        foreach ($orders as $order) {
            $brokerOrderId = (string) $order->broker_order_id;
            $snapshot = $snapshotByBrokerOrderId[$brokerOrderId] ?? null;

            if (! is_array($snapshot)) {
                continue;
            }

            $normalizedStatus = $this->normalizeBrokerStatus(
                brokerStatus: (string) ($snapshot['broker_status'] ?? ''),
                lastMessage: (string) Arr::get($snapshot, 'diagnostics.last_message', ''),
            );

            $newMeta = [
                ...($order->meta_json ?? []),
                'sync_snapshot' => $snapshot,
            ];

            $orderWasUpdated = false;
            if ($order->status !== $normalizedStatus) {
                $order->status = $normalizedStatus;
                if ($normalizedStatus === 'filled_paper') {
                    $order->filled_at = now('UTC');
                }
                $orderWasUpdated = true;
            }

            if (($order->meta_json ?? null) !== $newMeta) {
                $order->meta_json = $newMeta;
                $orderWasUpdated = true;
            }

            if ($orderWasUpdated) {
                $order->save();
                $summary['orders_updated']++;
                $changedTradeSetupIds[$order->trade_setup_id] = true;
            }
        }

        foreach (array_keys($changedTradeSetupIds) as $tradeSetupId) {
            $setupResult = $this->syncTradeSetupStatus((int) $tradeSetupId);
            if ($setupResult === 'entered') {
                $summary['trade_setups_entered']++;
            } elseif ($setupResult === 'cancelled') {
                $summary['trade_setups_cancelled']++;
            } elseif ($setupResult === 'closed') {
                $summary['trade_setups_closed']++;
            }
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function terminalOrderStatuses(): array
    {
        return [
            'filled_paper',
            'broker_rejected',
            'broker_rejected_cash',
            'cancelled_parent_rejected',
            'cancelled',
        ];
    }

    private function normalizeBrokerStatus(string $brokerStatus, string $lastMessage): string
    {
        $normalized = strtolower(trim($brokerStatus));

        return match ($normalized) {
            'pendingsubmit', 'presubmitted', 'submitted' => 'submitted_paper',
            'filled' => 'filled_paper',
            'partiallyfilled' => 'partially_filled_paper',
            'cancelled', 'apicancelled' => 'cancelled',
            'rejected' => str_contains(strtolower($lastMessage), 'cash available')
                ? 'broker_rejected_cash'
                : 'broker_rejected',
            'inactive' => str_contains(strtolower($lastMessage), 'cash available')
                ? 'broker_rejected_cash'
                : 'inactive_broker',
            default => $normalized === '' ? 'unknown_broker_state' : 'unknown_broker_state',
        };
    }

    /**
     * @param list<string> $brokerOrderIds
     * @return array<string, array<string, mixed>>
     */
    private function fetchStatusesByBrokerOrderId(array $brokerOrderIds): array
    {
        $pythonExecutable = (string) config('services.trade_status_sync.python_executable', 'python');
        $scriptPath = (string) config('services.trade_status_sync.script_path', '');

        if ($scriptPath === '' || ! is_file($scriptPath)) {
            throw new RuntimeException('Order status sync script path is missing or invalid: '.$scriptPath);
        }

        $command = [
            $pythonExecutable,
            $scriptPath,
            '--order-ids',
            implode(',', $brokerOrderIds),
        ];

        $process = new Process($command, base_path(), [
            ...$_ENV,
            'TRADING_MODE' => 'paper',
        ]);
        $process->setTimeout((float) config('services.trade_status_sync.timeout_seconds', 30));
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'unknown process error';
            throw new RuntimeException('Order status sync python script failed: '.$message);
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (! is_array($decoded) || ($decoded['status'] ?? null) !== 'success') {
            throw new RuntimeException('Order status sync script returned invalid payload.');
        }

        $result = [];
        foreach (($decoded['orders'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['broker_order_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $result[$id] = $row;
        }

        return $result;
    }

    private function syncTradeSetupStatus(int $tradeSetupId): ?string
    {
        $tradeSetup = TradeSetup::query()->with('orders')->find($tradeSetupId);
        if (! $tradeSetup) {
            return null;
        }

        $previous = (string) $tradeSetup->status;

        $entryOrders = $tradeSetup->orders->filter(fn (Order $order): bool => strtoupper($order->side) === 'BUY');
        $entryFilled = $entryOrders->contains(fn (Order $order): bool => $order->status === 'filled_paper');

        if ($entryFilled && $tradeSetup->status !== 'entered') {
            $tradeSetup->status = 'entered';
            $tradeSetup->save();

            return 'entered';
        }

        $entryTerminalRejectedOrCancelled = $entryOrders->isNotEmpty() && $entryOrders->every(
            fn (Order $order): bool => in_array($order->status, [
                'cancelled',
                'cancelled_parent_rejected',
                'broker_rejected',
                'broker_rejected_cash',
                'inactive_broker',
            ], true)
        );

        if (! $entryFilled && $entryTerminalRejectedOrCancelled && $tradeSetup->status !== 'cancelled') {
            $tradeSetup->status = 'cancelled';
            $tradeSetup->save();

            return 'cancelled';
        }

        $exitFilled = $tradeSetup->orders->contains(function (Order $order): bool {
            $snapshot = Arr::get($order->meta_json ?? [], 'sync_snapshot');
            if (! is_array($snapshot)) {
                return false;
            }

            $takeProfitStatus = strtolower((string) Arr::get($snapshot, 'child_statuses.take_profit', ''));
            $stopLossStatus = strtolower((string) Arr::get($snapshot, 'child_statuses.stop_loss', ''));

            return $takeProfitStatus === 'filled' || $stopLossStatus === 'filled' || (strtoupper($order->side) === 'SELL' && $order->status === 'filled_paper');
        });

        if ($exitFilled && in_array($previous, ['entered', 'open'], true) && $tradeSetup->status !== 'closed') {
            $tradeSetup->status = 'closed';
            $tradeSetup->save();

            return 'closed';
        }

        return null;
    }
}

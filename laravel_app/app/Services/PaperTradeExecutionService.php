<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TradeSetup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class PaperTradeExecutionService
{
    /**
     * @return array{status:string,message:string,order_id:int|null}
     */
    public function executeForSetup(TradeSetup $tradeSetup, string $symbol): array
    {
        $isExecutionEnabled = (bool) config('services.trade_execution.enabled', false);
        $brokerMode = strtolower((string) config('services.trade_execution.broker_trading_mode', 'paper'));

        if (! $isExecutionEnabled || $brokerMode !== 'paper') {
            Log::info('paper execution skipped', [
                'execution_enabled' => $isExecutionEnabled,
                'broker_trading_mode' => $brokerMode,
                'trade_setup_id' => $tradeSetup->id,
                'symbol' => $symbol,
            ]);

            return [
                'status' => 'skipped',
                'message' => 'paper execution is disabled by configuration',
                'order_id' => null,
            ];
        }

        $tradeSetup->loadMissing('sourceCandidate');

        $setupType = strtolower((string) optional($tradeSetup->sourceCandidate)->setup_type);
        if (! in_array($setupType, ['breakout', 'pullback'], true)) {
            $setupType = 'pullback';
        }

        $quantity = (float) config('services.trade_execution.paper_order_quantity', 1);
        $dryRun = (bool) config('services.trade_execution.dry_run', true);
        $entryPrice = (float) $tradeSetup->entry_price;
        $stopPrice = (float) $tradeSetup->stop_price;
        $target1Price = (float) $tradeSetup->target1_price;

        Log::info('placing setup-aware paper bracket order', [
            'symbol' => strtoupper(trim($symbol)),
            'trade_setup_id' => $tradeSetup->id,
            'setup_type' => $setupType,
            'quantity' => $quantity,
            'dry_run' => $dryRun,
            'broker_trading_mode' => $brokerMode,
        ]);

        try {
            $response = $this->runPythonOrderPlacement(
                symbol: $symbol,
                setupType: $setupType,
                entryPrice: $entryPrice,
                stopPrice: $stopPrice,
                target1Price: $target1Price,
                quantity: $quantity,
                dryRun: $dryRun,
                brokerMode: $brokerMode,
            );

            if (($response['status'] ?? null) !== 'success') {
                throw new RuntimeException((string) ($response['error'] ?? 'unknown order placement error'));
            }

            $parentOrder = $response['orders']['parent'] ?? null;
            if (! is_array($parentOrder)) {
                throw new RuntimeException('Order placement response missing parent order details.');
            }

            $brokerOrderId = null;
            if (! $dryRun) {
                $brokerOrderId = (string) (($response['broker_order_ids']['parent'] ?? null) ?: '');
                if ($brokerOrderId === '') {
                    throw new RuntimeException('Paper order placement succeeded but parent broker order id is missing.');
                }
            }

            $parentBrokerStatus = strtolower((string) ($response['broker_statuses']['parent'] ?? ''));
            $statusByBrokerState = [
                'cancelled' => 'cancelled_paper',
                'inactive' => 'rejected_paper',
                'rejected' => 'rejected_paper',
                'apicancelled' => 'cancelled_paper',
                'pendingsubmit' => 'submitted_paper',
                'presubmitted' => 'submitted_paper',
                'submitted' => 'submitted_paper',
                'filled' => 'filled_paper',
                'partiallyfilled' => 'partially_filled_paper',
            ];
            $storedStatus = $dryRun
                ? 'simulated_dry_run'
                : ($statusByBrokerState[$parentBrokerStatus] ?? 'submitted_paper');

            $executionNote = 'paper bracket transmitted to broker';
            if ($dryRun) {
                $executionNote = 'dry-run only: no broker transmission';
            } elseif ($storedStatus === 'rejected_paper') {
                $lastBrokerMessage = (string) ($response['broker_diagnostics']['parent']['last_message'] ?? '');
                $executionNote = $lastBrokerMessage !== ''
                    ? 'paper bracket rejected by broker: '.$lastBrokerMessage
                    : 'paper bracket rejected by broker';
            } elseif ($storedStatus === 'cancelled_paper') {
                $executionNote = 'paper bracket cancelled by broker';
            }

            $orderPayload = [
                'trade_setup_id' => $tradeSetup->id,
                'broker_order_id' => $brokerOrderId,
                'order_type' => (string) ($parentOrder['order_type'] ?? 'UNKNOWN'),
                'side' => (string) ($parentOrder['action'] ?? 'BUY'),
                'quantity' => (float) ($parentOrder['quantity'] ?? $quantity),
                'limit_price' => isset($parentOrder['limit_price']) ? (float) $parentOrder['limit_price'] : null,
                'stop_price' => isset($parentOrder['stop_price']) ? (float) $parentOrder['stop_price'] : null,
                'status' => $storedStatus,
                'placed_at' => now('UTC'),
                'meta_json' => [
                    ...$response,
                    'execution_note' => $executionNote,
                ],
            ];

            if (Schema::hasColumn('orders', 'symbol_id')) {
                $orderPayload['symbol_id'] = $tradeSetup->symbol_id;
            }

            $order = Order::create($orderPayload);

            Log::info('paper execution completed', [
                'symbol' => strtoupper(trim($symbol)),
                'trade_setup_id' => $tradeSetup->id,
                'dry_run' => $dryRun,
                'status' => $orderPayload['status'],
                'parent_broker_order_id' => $brokerOrderId,
            ]);

            return [
                'status' => 'success',
                'message' => 'paper execution completed',
                'order_id' => $order->id,
            ];
        } catch (Throwable $throwable) {
            Log::error('order placement error', [
                'symbol' => strtoupper(trim($symbol)),
                'trade_setup_id' => $tradeSetup->id,
                'message' => $throwable->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => $throwable->getMessage(),
                'order_id' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runPythonOrderPlacement(
        string $symbol,
        string $setupType,
        float $entryPrice,
        float $stopPrice,
        float $target1Price,
        float $quantity,
        bool $dryRun,
        string $brokerMode,
    ): array {
        $pythonExecutable = (string) config('services.trade_execution.python_executable', 'python');
        $scriptPath = (string) config('services.trade_execution.script_path', '');
        $breakoutBuffer = (float) config('services.trade_execution.breakout_stop_limit_buffer', 0.10);

        if ($scriptPath === '' || ! is_file($scriptPath)) {
            throw new RuntimeException('Order placement script path is missing or invalid: '.$scriptPath);
        }

        $command = [
            $pythonExecutable,
            $scriptPath,
            '--symbol',
            strtoupper(trim($symbol)),
            '--setup-type',
            $setupType,
            '--entry-price',
            (string) $entryPrice,
            '--stop-price',
            (string) $stopPrice,
            '--target1-price',
            (string) $target1Price,
            '--quantity',
            (string) $quantity,
            '--breakout-stop-limit-buffer',
            (string) $breakoutBuffer,
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $process = new Process($command, base_path(), [
            ...$_ENV,
            'TRADING_MODE' => $brokerMode,
        ]);
        $process->setTimeout((float) config('services.trade_execution.timeout_seconds', 30));
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            $stdOutput = trim($process->getOutput());
            $message = $errorOutput !== '' ? $errorOutput : $stdOutput;
            throw new RuntimeException('Order placement python script failed: '.($message !== '' ? $message : 'unknown process error'));
        }

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Order placement script returned invalid JSON.');
        }

        return $decoded;
    }
}

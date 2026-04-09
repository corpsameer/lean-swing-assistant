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
    public function executeBuyLimitForSetup(TradeSetup $tradeSetup, string $symbol): void
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

            return;
        }

        $quantity = (float) config('services.trade_execution.default_quantity', 1);
        $entryPrice = (float) $tradeSetup->entry_price;

        Log::info('placing order...', [
            'symbol' => $symbol,
            'trade_setup_id' => $tradeSetup->id,
            'side' => 'BUY',
            'order_type' => 'LMT',
            'quantity' => $quantity,
            'price' => $entryPrice,
        ]);

        try {
            $response = $this->runPythonOrderPlacement($symbol, $entryPrice, $quantity, $brokerMode);

            if (($response['status'] ?? null) !== 'success') {
                throw new RuntimeException((string) ($response['error'] ?? 'unknown order placement error'));
            }

            $brokerOrderId = (string) ($response['order_id'] ?? '');
            if ($brokerOrderId === '') {
                throw new RuntimeException('Order placement succeeded but broker order_id is missing.');
            }

            $orderPayload = [
                'trade_setup_id' => $tradeSetup->id,
                'broker_order_id' => $brokerOrderId,
                'order_type' => 'LMT',
                'side' => 'BUY',
                'quantity' => $quantity,
                'limit_price' => $entryPrice,
                'status' => 'pending',
                'placed_at' => now('UTC'),
                'meta_json' => $response,
            ];

            if (Schema::hasColumn('orders', 'symbol_id')) {
                $orderPayload['symbol_id'] = $tradeSetup->symbol_id;
            } else {
                Log::warning('orders.symbol_id column is missing; run migrations to persist symbol_id', [
                    'trade_setup_id' => $tradeSetup->id,
                    'symbol' => $symbol,
                ]);
            }

            Order::create($orderPayload);

            Log::info('order placed successfully', [
                'symbol' => $symbol,
                'trade_setup_id' => $tradeSetup->id,
                'order_id' => $brokerOrderId,
            ]);
            Log::info('order_id', ['order_id' => $brokerOrderId]);
        } catch (Throwable $throwable) {
            Log::error('order placement error', [
                'symbol' => $symbol,
                'trade_setup_id' => $tradeSetup->id,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runPythonOrderPlacement(string $symbol, float $entryPrice, float $quantity, string $brokerMode): array
    {
        $pythonExecutable = (string) config('services.trade_execution.python_executable', 'python');
        $scriptPath = (string) config('services.trade_execution.script_path', '');

        if ($scriptPath === '' || ! is_file($scriptPath)) {
            throw new RuntimeException('Order placement script path is missing or invalid: '.$scriptPath);
        }

        $command = [
            $pythonExecutable,
            $scriptPath,
            '--symbol',
            strtoupper(trim($symbol)),
            '--entry-price',
            (string) $entryPrice,
            '--quantity',
            (string) $quantity,
        ];

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

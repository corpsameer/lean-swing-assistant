<?php

namespace Tests\Feature;

use App\Models\MarketSnapshot;
use App\Models\Order;
use App\Models\Run;
use App\Models\Symbol;
use App\Models\TradeSetup;
use App\Models\WatchlistCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulateTradeStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_breakout_not_triggered_remains_pending_and_planned(): void
    {
        [$tradeSetup, $order, $symbol] = $this->createBreakoutPendingSetup(200.0);
        $this->createIntradaySnapshot($symbol->id, 199.0);

        $this->artisan('trades:simulate-status')
            ->expectsOutputToContain('simulated orders scanned: 1')
            ->expectsOutputToContain('entered count: 0')
            ->expectsOutputToContain('no_change: entry condition not met')
            ->assertExitCode(0);

        $order->refresh();
        $tradeSetup->refresh();

        $this->assertSame('simulated_pending', $order->status);
        $this->assertSame('planned', $tradeSetup->status);
    }

    public function test_b_pending_breakout_triggered_becomes_entered(): void
    {
        [$tradeSetup, $order, $symbol] = $this->createBreakoutPendingSetup(200.0);
        $this->createIntradaySnapshot($symbol->id, 200.0);

        $this->artisan('trades:simulate-status')
            ->expectsOutputToContain('current_price=200')
            ->expectsOutputToContain('entered count: 1')
            ->assertExitCode(0);

        $order->refresh();
        $tradeSetup->refresh();

        $this->assertSame('simulated_entered', $order->status);
        $this->assertSame('entered', $tradeSetup->status);
        $this->assertSame(200.0, (float) $order->meta_json['simulated_entry_price']);
        $this->assertArrayHasKey('simulated_entered_at', $order->meta_json);
    }

    public function test_c_entered_trade_hits_tp_and_closes(): void
    {
        [$tradeSetup, $order, $symbol] = $this->createEnteredSetup(200.0, 210.0, 195.0);
        $this->createIntradaySnapshot($symbol->id, 210.0);

        $this->artisan('trades:simulate-status')
            ->expectsOutputToContain('tp hit count: 1')
            ->expectsOutputToContain('closed_trade symbol=AAPL')
            ->assertExitCode(0);

        $order->refresh();
        $tradeSetup->refresh();

        $this->assertSame('simulated_tp_hit', $order->status);
        $this->assertSame('closed', $tradeSetup->status);
        $this->assertSame('target1_hit', $order->meta_json['exit_reason']);
        $this->assertSame(10.0, (float) $order->meta_json['pnl_amount']);
        $this->assertSame(5.0, (float) $order->meta_json['pnl_percent']);
        $this->assertSame(5.0, (float) $order->meta_json['risk_amount']);
        $this->assertSame(2.0, (float) $order->meta_json['r_multiple']);
    }

    public function test_d_entered_trade_hits_sl_and_closes(): void
    {
        [$tradeSetup, $order, $symbol] = $this->createEnteredSetup(200.0, 210.0, 195.0);
        $this->createIntradaySnapshot($symbol->id, 195.0);

        $this->artisan('trades:simulate-status')
            ->expectsOutputToContain('sl hit count: 1')
            ->assertExitCode(0);

        $order->refresh();
        $tradeSetup->refresh();

        $this->assertSame('simulated_sl_hit', $order->status);
        $this->assertSame('closed', $tradeSetup->status);
        $this->assertSame('stop_loss_hit', $order->meta_json['exit_reason']);
        $this->assertSame(-5.0, (float) $order->meta_json['pnl_amount']);
        $this->assertSame(-2.5, (float) $order->meta_json['pnl_percent']);
        $this->assertSame(5.0, (float) $order->meta_json['risk_amount']);
        $this->assertSame(-1.0, (float) $order->meta_json['r_multiple']);
    }

    private function createBreakoutPendingSetup(float $entryPrice): array
    {
        $tradeSetup = $this->createTradeSetup($entryPrice, 195.0, 210.0, 'planned', 'breakout');
        $order = Order::query()->create([
            'trade_setup_id' => $tradeSetup->id,
            'symbol_id' => $tradeSetup->symbol_id,
            'order_type' => 'STP LMT',
            'side' => 'BUY',
            'quantity' => 1,
            'limit_price' => $entryPrice,
            'stop_price' => $entryPrice,
            'status' => 'simulated_pending',
            'placed_at' => now('UTC'),
            'meta_json' => [
                'execution_driver' => 'simulated',
                'setup_type' => 'breakout',
                'entry_price' => $entryPrice,
                'stop_loss_price' => 195.0,
                'take_profit_price' => 210.0,
            ],
        ]);

        return [$tradeSetup, $order, $tradeSetup->symbol];
    }

    private function createEnteredSetup(float $entryPrice, float $takeProfitPrice, float $stopPrice): array
    {
        $tradeSetup = $this->createTradeSetup($entryPrice, $stopPrice, $takeProfitPrice, 'entered', 'breakout');
        $order = Order::query()->create([
            'trade_setup_id' => $tradeSetup->id,
            'symbol_id' => $tradeSetup->symbol_id,
            'order_type' => 'STP LMT',
            'side' => 'BUY',
            'quantity' => 1,
            'limit_price' => $entryPrice,
            'stop_price' => $entryPrice,
            'status' => 'simulated_entered',
            'placed_at' => now('UTC'),
            'filled_at' => now('UTC'),
            'meta_json' => [
                'execution_driver' => 'simulated',
                'setup_type' => 'breakout',
                'entry_price' => $entryPrice,
                'stop_loss_price' => $stopPrice,
                'take_profit_price' => $takeProfitPrice,
            ],
        ]);

        return [$tradeSetup, $order, $tradeSetup->symbol];
    }

    private function createTradeSetup(float $entry, float $stop, float $target, string $status, string $setupType): TradeSetup
    {
        $symbol = Symbol::query()->create([
            'symbol' => 'AAPL',
            'company_name' => 'Apple Inc.',
            'exchange' => 'NASDAQ',
            'sector' => 'Technology',
            'is_active' => true,
        ]);

        $run = Run::query()->create([
            'run_type' => 'intraday_validate',
            'status' => 'completed',
            'started_at' => now('UTC'),
            'completed_at' => now('UTC'),
        ]);

        $candidate = WatchlistCandidate::query()->create([
            'run_id' => $run->id,
            'symbol_id' => $symbol->id,
            'stage' => 'intraday',
            'status' => 'keep',
            'setup_type' => $setupType,
            'created_at' => now('UTC'),
        ]);

        return TradeSetup::query()->create([
            'symbol_id' => $symbol->id,
            'source_candidate_id' => $candidate->id,
            'status' => $status,
            'entry_price' => $entry,
            'stop_price' => $stop,
            'target1_price' => $target,
            'target2_price' => null,
        ]);
    }

    private function createIntradaySnapshot(int $symbolId, float $price): void
    {
        $run = Run::query()->create([
            'run_type' => 'ingest_daily_bars',
            'status' => 'completed',
            'started_at' => now('UTC'),
            'completed_at' => now('UTC'),
        ]);

        MarketSnapshot::query()->create([
            'run_id' => $run->id,
            'symbol_id' => $symbolId,
            'snapshot_type' => 'intraday',
            'payload_json' => [
                'metrics' => [
                    'current_price' => $price,
                ],
            ],
            'created_at' => now('UTC'),
        ]);
    }
}

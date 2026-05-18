<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Run;
use App\Models\Symbol;
use App\Models\TradeSetup;
use App\Models\WatchlistCandidate;
use App\Services\PaperTradeExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperTradeExecutionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_execution_disabled_safely_skips_order_placement(): void
    {
        config()->set('services.trade_execution.enabled', false);
        config()->set('services.trade_execution.broker_trading_mode', 'paper');
        config()->set('services.trade_execution.execution_driver', 'ibkr');
        config()->set('services.trade_execution.script_path', base_path('tests/Fixtures/does_not_exist.php'));

        $tradeSetup = $this->createTradeSetup('breakout');

        $result = app(PaperTradeExecutionService::class)->executeForSetup($tradeSetup, 'AAPL');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('execution_disabled', $result['status']);
        $this->assertFalse($result['order_created']);
        $this->assertFalse($result['broker_called']);
    }


    public function test_simulated_driver_creates_local_pending_order_without_python_call(): void
    {
        config()->set('services.trade_execution.enabled', true);
        config()->set('services.trade_execution.broker_trading_mode', 'paper');
        config()->set('services.trade_execution.execution_driver', 'simulated');
        config()->set('services.trade_execution.paper_order_quantity', 3);
        config()->set('services.trade_execution.script_path', base_path('tests/Fixtures/does_not_exist.php'));

        $tradeSetup = $this->createTradeSetup('breakout');

        $result = app(PaperTradeExecutionService::class)->executeForSetup($tradeSetup, 'AAPL');

        /** @var Order $order */
        $order = Order::query()->firstOrFail();

        $this->assertSame('simulated_pending', $order->status);
        $this->assertSame('STP LMT', $order->order_type);
        $this->assertSame('BUY', $order->side);
        $this->assertEquals('3.0000', (string) $order->quantity);
        $this->assertSame(184.5, (float) $order->limit_price);
        $this->assertSame(184.5, (float) $order->stop_price);
        $this->assertSame('simulated', $order->meta_json['execution_driver']);
        $this->assertTrue((bool) $order->meta_json['simulated']);
        $this->assertSame(188.0, (float) $order->meta_json['bracket']['take_profit']);
        $this->assertSame(182.5, (float) $order->meta_json['bracket']['stop_loss']);
        $this->assertSame(190.0, (float) $order->meta_json['bracket']['target2_price']);
        $this->assertSame('simulated_pending', $result['status']);
        $this->assertTrue($result['order_created']);
        $this->assertFalse($result['broker_called']);
    }

    public function test_ibkr_dry_run_mode_creates_no_order_and_skips_broker_call(): void
    {
        config()->set('services.trade_execution.enabled', true);
        config()->set('services.trade_execution.broker_trading_mode', 'paper');
        config()->set('services.trade_execution.execution_driver', 'ibkr');
        config()->set('services.trade_execution.dry_run', true);
        config()->set('services.trade_execution.paper_order_quantity', 1);
        config()->set('services.trade_execution.breakout_stop_limit_buffer', 0.10);
        config()->set('services.trade_execution.script_path', base_path('tests/Fixtures/does_not_exist.php'));

        $tradeSetup = $this->createTradeSetup('breakout');

        $result = app(PaperTradeExecutionService::class)->executeForSetup($tradeSetup, 'AAPL');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('dry_run_only', $result['status']);
        $this->assertFalse($result['order_created']);
        $this->assertFalse($result['broker_called']);
    }

    public function test_paper_mode_marks_inactive_parent_as_rejected(): void
    {
        config()->set('services.trade_execution.enabled', true);
        config()->set('services.trade_execution.broker_trading_mode', 'paper');
        config()->set('services.trade_execution.execution_driver', 'ibkr');
        config()->set('services.trade_execution.dry_run', false);
        config()->set('services.trade_execution.paper_order_quantity', 1);
        config()->set('services.trade_execution.python_executable', 'php');
        config()->set('services.trade_execution.script_path', base_path('tests/Fixtures/fake_place_order_rejected.php'));

        $tradeSetup = $this->createTradeSetup('breakout');

        $result = app(PaperTradeExecutionService::class)->executeForSetup($tradeSetup, 'AAPL');

        /** @var Order $order */
        $order = Order::query()->firstOrFail();

        $this->assertSame('rejected_paper', $order->status);
        $this->assertSame('broker_rejected', $result['status']);
        $this->assertStringContainsString('paper bracket rejected by broker', (string) $order->meta_json['execution_note']);
        $this->assertStringContainsString('CASH AVAILABLE: 0.00', (string) $order->meta_json['execution_note']);
    }

    public function test_paper_mode_stores_broker_parent_order_id(): void
    {
        config()->set('services.trade_execution.enabled', true);
        config()->set('services.trade_execution.broker_trading_mode', 'paper');
        config()->set('services.trade_execution.execution_driver', 'ibkr');
        config()->set('services.trade_execution.dry_run', false);
        config()->set('services.trade_execution.paper_order_quantity', 1);
        config()->set('services.trade_execution.python_executable', 'php');
        config()->set('services.trade_execution.script_path', base_path('tests/Fixtures/fake_place_order.php'));

        $tradeSetup = $this->createTradeSetup('pullback');

        $result = app(PaperTradeExecutionService::class)->executeForSetup($tradeSetup, 'AAPL');

        /** @var Order $order */
        $order = Order::query()->firstOrFail();

        $this->assertSame('submitted_paper', $order->status);
        $this->assertSame('submitted_paper', $result['status']);
        $this->assertSame('1001', $order->broker_order_id);
        $this->assertSame('LMT', $order->order_type);
        $this->assertFalse((bool) $order->meta_json['dry_run']);
        $this->assertSame(1002, $order->meta_json['broker_order_ids']['take_profit']);
    }

    private function createTradeSetup(string $setupType): TradeSetup
    {
        $symbol = Symbol::query()->create([
            'symbol' => 'AAPL',
            'company_name' => 'Apple Inc.',
            'exchange' => 'NASDAQ',
            'sector' => 'Technology',
            'is_active' => true,
        ]);

        $run = Run::query()->create([
            'run_type' => 'weekend_rank',
            'status' => 'completed',
            'started_at' => now('UTC'),
            'completed_at' => now('UTC'),
        ]);

        $candidate = WatchlistCandidate::query()->create([
            'run_id' => $run->id,
            'symbol_id' => $symbol->id,
            'stage' => 'weekend',
            'status' => 'keep',
            'setup_type' => $setupType,
            'created_at' => now('UTC'),
        ]);

        return TradeSetup::query()->create([
            'symbol_id' => $symbol->id,
            'source_candidate_id' => $candidate->id,
            'status' => 'planned',
            'entry_price' => 184.50,
            'stop_price' => 182.50,
            'target1_price' => 188.00,
            'target2_price' => 190.00,
        ]);
    }
}

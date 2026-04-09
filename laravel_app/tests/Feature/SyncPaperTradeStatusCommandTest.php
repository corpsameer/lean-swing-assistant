<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Run;
use App\Models\Symbol;
use App\Models\TradeSetup;
use App\Models\WatchlistCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPaperTradeStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_command_exits_cleanly_when_no_relevant_open_orders_exist(): void
    {
        $this->artisan('trades:sync-paper-status')
            ->expectsOutputToContain('orders checked: 0')
            ->expectsOutputToContain('orders updated: 0')
            ->expectsOutputToContain('errors: 0')
            ->assertExitCode(0);
    }

    public function test_b_submitted_paper_order_is_fetched_and_snapshot_is_stored(): void
    {
        config()->set('services.trade_status_sync.python_executable', 'php');
        config()->set('services.trade_status_sync.script_path', base_path('tests/Fixtures/fake_fetch_order_status_submitted.php'));

        $tradeSetup = $this->createTradeSetup();
        $order = Order::query()->create([
            'trade_setup_id' => $tradeSetup->id,
            'broker_order_id' => '1001',
            'order_type' => 'LMT',
            'side' => 'BUY',
            'quantity' => 1,
            'limit_price' => 184.50,
            'status' => 'submitted_paper',
            'placed_at' => now('UTC'),
        ]);

        $this->artisan('trades:sync-paper-status')
            ->expectsOutputToContain('orders checked: 1')
            ->expectsOutputToContain('orders updated: 1')
            ->expectsOutputToContain('errors: 0')
            ->assertExitCode(0);

        $order->refresh();
        $this->assertSame('submitted_paper', $order->status);
        $this->assertSame('Submitted', $order->meta_json['sync_snapshot']['broker_status']);
    }

    public function test_c_rejected_order_updates_local_status_and_cancels_trade_setup(): void
    {
        config()->set('services.trade_status_sync.python_executable', 'php');
        config()->set('services.trade_status_sync.script_path', base_path('tests/Fixtures/fake_fetch_order_status_rejected.php'));

        $tradeSetup = $this->createTradeSetup();
        $order = Order::query()->create([
            'trade_setup_id' => $tradeSetup->id,
            'broker_order_id' => '1002',
            'order_type' => 'LMT',
            'side' => 'BUY',
            'quantity' => 1,
            'limit_price' => 184.50,
            'status' => 'submitted_paper',
            'placed_at' => now('UTC'),
        ]);

        $this->artisan('trades:sync-paper-status')
            ->expectsOutputToContain('orders updated: 1')
            ->expectsOutputToContain('trade setups cancelled: 1')
            ->assertExitCode(0);

        $order->refresh();
        $tradeSetup->refresh();

        $this->assertSame('broker_rejected_cash', $order->status);
        $this->assertSame('cancelled', $tradeSetup->status);
    }

    public function test_d_entry_order_filled_marks_order_and_trade_setup_entered(): void
    {
        config()->set('services.trade_status_sync.python_executable', 'php');
        config()->set('services.trade_status_sync.script_path', base_path('tests/Fixtures/fake_fetch_order_status_filled.php'));

        $tradeSetup = $this->createTradeSetup();
        $order = Order::query()->create([
            'trade_setup_id' => $tradeSetup->id,
            'broker_order_id' => '1003',
            'order_type' => 'STP LMT',
            'side' => 'BUY',
            'quantity' => 1,
            'limit_price' => 184.60,
            'stop_price' => 184.50,
            'status' => 'submitted_paper',
            'placed_at' => now('UTC'),
        ]);

        $this->artisan('trades:sync-paper-status')
            ->expectsOutputToContain('orders updated: 1')
            ->expectsOutputToContain('trade setups entered: 1')
            ->assertExitCode(0);

        $order->refresh();
        $tradeSetup->refresh();

        $this->assertSame('filled_paper', $order->status);
        $this->assertNotNull($order->filled_at);
        $this->assertSame('entered', $tradeSetup->status);
    }

    private function createTradeSetup(): TradeSetup
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
            'setup_type' => 'breakout',
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

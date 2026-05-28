<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Run;
use App\Models\Symbol;
use App\Models\TradeReview;
use App\Models\TradeSetup;
use App\Models\WatchlistCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TradeReviewPromptCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exits_cleanly_when_no_eligible_closed_trades_exist(): void
    {
        $this->artisan('prompt:trade-review')
            ->expectsOutputToContain('closed trades found: 0')
            ->expectsOutputToContain('no eligible closed trades found')
            ->expectsOutputToContain('errors: 0')
            ->assertSuccessful();
    }

    public function test_it_creates_review_skips_duplicates_and_supports_force_update(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        $order = $this->createClosedSimulatedOrder('simulated_tp_hit');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Setup was acceptable with disciplined risk.',
                            'setup_quality_score' => 78,
                            'entry_quality_score' => 75,
                            'risk_reward_quality_score' => 80,
                            'execution_quality_score' => 74,
                            'outcome_explanation' => 'TP was reached after a clean continuation move.',
                            'what_worked' => ['Clear trigger band alignment'],
                            'what_failed' => ['No major failure observed'],
                            'improvement_notes' => ['Collect more intraday context before entry'],
                            'future_rule_suggestions' => ['Prefer similar setups with relative volume confirmation'],
                            'final_verdict' => 'prefer_similar',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $this->artisan('prompt:trade-review --limit=1')
            ->expectsOutputToContain('closed trades found: 1')
            ->expectsOutputToContain('trades reviewed: 1')
            ->expectsOutputToContain('trades skipped because already reviewed: 0')
            ->expectsOutputToContain('errors: 0')
            ->assertSuccessful();

        $review = TradeReview::query()->where('trade_setup_id', $order->trade_setup_id)->first();
        $this->assertNotNull($review);
        $this->assertSame('Setup was acceptable with disciplined risk.', $review->review_text);
        $this->assertSame('prefer_similar', $review->lessons_json['final_verdict']);

        $this->artisan('prompt:trade-review --limit=1')
            ->expectsOutputToContain('trades reviewed: 0')
            ->expectsOutputToContain('trades skipped because already reviewed: 1')
            ->assertSuccessful();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Forced re-review updated this record.',
                            'setup_quality_score' => 70,
                            'entry_quality_score' => 68,
                            'risk_reward_quality_score' => 72,
                            'execution_quality_score' => 67,
                            'outcome_explanation' => 'Same trade reassessed in force mode.',
                            'what_worked' => ['Risk was defined'],
                            'what_failed' => ['Entry was late'],
                            'improvement_notes' => ['Tighter trigger discipline'],
                            'future_rule_suggestions' => ['Watch similar setups carefully'],
                            'final_verdict' => 'neutral',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $this->artisan('prompt:trade-review --limit=1 --force')
            ->expectsOutputToContain('force mode enabled: existing reviews may be updated')
            ->expectsOutputToContain('trades reviewed: 1')
            ->assertSuccessful();
    }

    private function createClosedSimulatedOrder(string $status): Order
    {
        $symbol = Symbol::query()->create(['symbol' => 'AAPL', 'is_active' => true]);
        $run = Run::query()->create(['run_type' => 'daily_refine', 'status' => 'completed', 'started_at' => now('UTC'), 'completed_at' => now('UTC')]);
        $candidate = WatchlistCandidate::query()->create([
            'run_id' => $run->id,
            'symbol_id' => $symbol->id,
            'stage' => 'weekend',
            'status' => 'keep',
            'setup_type' => 'breakout',
            'reasoning_text' => 'Strong relative strength and clean trigger.',
            'created_at' => now('UTC'),
        ]);
        $setup = TradeSetup::query()->create([
            'symbol_id' => $symbol->id,
            'source_candidate_id' => $candidate->id,
            'status' => 'closed',
            'entry_price' => 200,
            'stop_price' => 195,
            'target1_price' => 210,
        ]);

        return Order::query()->create([
            'trade_setup_id' => $setup->id,
            'symbol_id' => $symbol->id,
            'order_type' => 'STP LMT',
            'side' => 'BUY',
            'quantity' => 1,
            'limit_price' => 200,
            'stop_price' => 200,
            'status' => $status,
            'placed_at' => now('UTC'),
            'filled_at' => now('UTC'),
            'meta_json' => [
                'simulated_entry_price' => 200.0,
                'simulated_exit_price' => 210.0,
                'exit_reason' => 'target1_hit',
                'pnl_amount' => 10.0,
                'pnl_percent' => 5.0,
                'r_multiple' => 2.0,
                'simulated_entered_at' => now('UTC')->subMinutes(30)->toDateTimeString(),
                'simulated_closed_at' => now('UTC')->toDateTimeString(),
            ],
        ]);
    }
}


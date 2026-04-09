<?php

namespace Tests\Feature;

use App\Models\MarketSnapshot;
use App\Models\PromptLog;
use App\Models\Run;
use App\Models\Symbol;
use App\Models\WatchlistCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeekendPromptRankCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_and_updates_latest_weekend_candidates_with_one_batch_prompt_call(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.model', 'gpt-4.1-mini');

        $sourceRun = Run::create([
            'run_type' => 'compute_daily_metrics',
            'status' => 'completed',
            'started_at' => now('UTC')->subHours(2),
            'completed_at' => now('UTC')->subHours(2),
        ]);

        $olderWeekendRun = Run::create([
            'run_type' => 'weekend_scan',
            'status' => 'completed',
            'started_at' => now('UTC')->subHour(),
            'completed_at' => now('UTC')->subHour(),
        ]);

        $latestWeekendRun = Run::create([
            'run_type' => 'weekend_scan',
            'status' => 'completed',
            'started_at' => now('UTC')->subMinutes(30),
            'completed_at' => now('UTC')->subMinutes(30),
        ]);

        $aapl = Symbol::create(['symbol' => 'AAPL', 'is_active' => true]);
        $msft = Symbol::create(['symbol' => 'MSFT', 'is_active' => true]);

        MarketSnapshot::create([
            'run_id' => $sourceRun->id,
            'symbol_id' => $aapl->id,
            'snapshot_type' => 'derived_daily_metrics',
            'payload_json' => [
                'metrics' => [
                    'last_price' => 182.1,
                    'daily_change_percent' => 1.2,
                    'avg_volume_20d' => 54000000,
                    'atr_14' => 3.8,
                    'atr_percent' => 2.1,
                    'high_20d' => 184.5,
                    'low_20d' => 171.0,
                    'high_50d' => 188.2,
                    'low_50d' => 164.8,
                    'breakout_level' => 184.5,
                    'support_level' => 171.0,
                    'distance_to_breakout_percent' => 1.31,
                    'pullback_depth_percent' => 1.3,
                    'trend_state' => 'uptrend',
                    'extension_percent' => 6.5,
                    'relative_volume_simple' => 1.08,
                ],
            ],
            'created_at' => now('UTC')->subMinutes(25),
        ]);

        MarketSnapshot::create([
            'run_id' => $sourceRun->id,
            'symbol_id' => $msft->id,
            'snapshot_type' => 'derived_daily_metrics',
            'payload_json' => [
                'metrics' => [
                    'last_price' => 410.2,
                    'daily_change_percent' => 0.4,
                    'avg_volume_20d' => 23000000,
                    'atr_14' => 7.1,
                    'atr_percent' => 1.73,
                    'high_20d' => 415.0,
                    'low_20d' => 392.4,
                    'high_50d' => 420.0,
                    'low_50d' => 376.2,
                    'breakout_level' => 415.0,
                    'support_level' => 392.4,
                    'distance_to_breakout_percent' => 1.17,
                    'pullback_depth_percent' => 1.15,
                    'trend_state' => 'uptrend',
                    'extension_percent' => 4.5,
                    'relative_volume_simple' => 0.95,
                ],
            ],
            'created_at' => now('UTC')->subMinutes(25),
        ]);

        WatchlistCandidate::create([
            'run_id' => $olderWeekendRun->id,
            'symbol_id' => $aapl->id,
            'stage' => 'weekend',
            'status' => 'candidate',
            'setup_type' => 'breakout',
            'created_at' => now('UTC')->subHour(),
        ]);

        $latestAaplCandidate = WatchlistCandidate::create([
            'run_id' => $latestWeekendRun->id,
            'symbol_id' => $aapl->id,
            'stage' => 'weekend',
            'status' => 'candidate',
            'setup_type' => 'breakout',
            'created_at' => now('UTC')->subMinutes(20),
        ]);

        $latestMsftCandidate = WatchlistCandidate::create([
            'run_id' => $latestWeekendRun->id,
            'symbol_id' => $msft->id,
            'stage' => 'weekend',
            'status' => 'candidate',
            'setup_type' => 'pullback',
            'created_at' => now('UTC')->subMinutes(20),
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl_123',
                'model' => 'gpt-4.1-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'ranked_candidates' => [
                                    [
                                        'symbol' => 'AAPL',
                                        'keep' => true,
                                        'score_total' => 24,
                                        'setup_type' => 'breakout',
                                        'preferred_action' => 'ready_breakout',
                                        'upside_potential_summary' => 'Tight consolidation near highs with healthy liquidity.',
                                        'risk_flags' => ['extended if breakout fails'],
                                        'reasoning_text' => 'Strong trend and close to breakout level; monitor for clean volume confirmation.',
                                    ],
                                    [
                                        'symbol' => 'MSFT',
                                        'keep' => true,
                                        'score_total' => 22,
                                        'setup_type' => 'pullback',
                                        'preferred_action' => 'ready_pullback',
                                        'upside_potential_summary' => 'Orderly pullback in an uptrend can offer continuation entry.',
                                        'risk_flags' => ['relative volume slightly soft'],
                                        'reasoning_text' => 'Uptrend intact with moderate pullback depth; better if volume improves.',
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('prompt:weekend-rank')
            ->expectsOutput('Weekend prompt rank completed.')
            ->expectsOutputToContain('candidates sent: 2')
            ->expectsOutputToContain('candidates ranked: 2')
            ->expectsOutputToContain('candidates updated: 2')
            ->expectsOutputToContain('errors: 0')
            ->assertSuccessful();

        Http::assertSentCount(1);

        $latestAaplCandidate->refresh();
        $latestMsftCandidate->refresh();

        $this->assertSame('24.000', (string) $latestAaplCandidate->score_total);
        $this->assertSame('22.000', (string) $latestMsftCandidate->score_total);
        $this->assertSame('ready_breakout', $latestAaplCandidate->prompt_output_json['preferred_action']);
        $this->assertSame('ready_pullback', $latestMsftCandidate->prompt_output_json['preferred_action']);

        $this->assertDatabaseCount('prompt_logs', 1);
        $promptLog = PromptLog::query()->firstOrFail();
        $this->assertSame('A', $promptLog->prompt_type);
        $this->assertNull($promptLog->symbol_id);

        $run = Run::query()->where('run_type', 'weekend_prompt_rank')->latest('id')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->meta_json['candidates_sent']);
        $this->assertSame(2, $run->meta_json['candidates_ranked']);
        $this->assertSame(2, $run->meta_json['candidates_updated']);
    }
}

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

class DailyRefinePromptCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refines_latest_weekend_candidates_and_updates_daily_actionable_fields(): void
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

        $aapl = Symbol::firstOrCreate(['symbol' => 'AAPL'], ['is_active' => true]);
        $msft = Symbol::firstOrCreate(['symbol' => 'MSFT'], ['is_active' => true]);

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
            'score_total' => 24,
            'created_at' => now('UTC')->subMinutes(20),
        ]);

        $latestMsftCandidate = WatchlistCandidate::create([
            'run_id' => $latestWeekendRun->id,
            'symbol_id' => $msft->id,
            'stage' => 'weekend',
            'status' => 'candidate',
            'setup_type' => 'pullback',
            'score_total' => 22,
            'created_at' => now('UTC')->subMinutes(20),
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl_456',
                'model' => 'gpt-4.1-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'refined_candidates' => [
                                    [
                                        'symbol' => 'AAPL',
                                        'decision' => 'keep',
                                        'setup_type' => 'breakout',
                                        'trigger_band_low' => 184.2,
                                        'trigger_band_high' => 185.0,
                                        'invalidation_note' => 'Break below 20D support zone.',
                                        'confidence' => 0.82,
                                        'reasoning_text' => 'Trend remains intact and price is near breakout level with supportive volume.',
                                    ],
                                    [
                                        'symbol' => 'MSFT',
                                        'decision' => 'wait',
                                        'setup_type' => 'pullback',
                                        'trigger_band_low' => 401.5,
                                        'trigger_band_high' => 404.0,
                                        'invalidation_note' => 'Loss of 50D trend support.',
                                        'confidence' => 0.68,
                                        'reasoning_text' => 'Needs cleaner pullback stabilization before becoming actionable.',
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('prompt:daily-refine')
            ->expectsOutput('Daily refine prompt completed.')
            ->expectsOutputToContain('candidates sent: 2')
            ->expectsOutputToContain('candidates refined: 2')
            ->expectsOutputToContain('candidates updated: 2')
            ->expectsOutputToContain('errors: 0')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return data_get($payload, 'response_format.json_schema.name') === 'daily_watchlist_refiner';
        });

        $latestAaplCandidate->refresh();
        $latestMsftCandidate->refresh();

        $this->assertSame('keep', $latestAaplCandidate->status);
        $this->assertSame('wait', $latestMsftCandidate->status);
        $this->assertSame('184.2000', (string) $latestAaplCandidate->trigger_band_low);
        $this->assertSame('404.0000', (string) $latestMsftCandidate->trigger_band_high);
        $this->assertSame('Loss of 50D trend support.', $latestMsftCandidate->prompt_output_json['invalidation_note']);

        $run = Run::query()->where('run_type', 'daily_refine')->latest('id')->firstOrFail();

        $this->assertSame(1, PromptLog::query()->where('run_id', $run->id)->where('prompt_type', 'B')->count());
        $promptLog = PromptLog::query()->where('run_id', $run->id)->where('prompt_type', 'B')->firstOrFail();
        $this->assertNull($promptLog->symbol_id);

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->meta_json['candidates_sent']);
        $this->assertSame(2, $run->meta_json['candidates_refined']);
        $this->assertSame(2, $run->meta_json['candidates_updated']);
    }
}

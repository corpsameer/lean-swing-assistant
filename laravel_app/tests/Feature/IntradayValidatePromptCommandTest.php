<?php

namespace Tests\Feature;

use App\Models\MarketSnapshot;
use App\Models\PromptLog;
use App\Models\Run;
use App\Models\Symbol;
use App\Models\TradeSetup;
use App\Models\WatchlistCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntradayValidatePromptCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_only_eligible_candidates_and_creates_planned_setups_without_duplicates(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.model', 'gpt-4.1-mini');
        config()->set('services.intraday_validation.near_band_tolerance_percent', 0.75);
        config()->set('services.intraday_validation.max_extension_percent', 1.5);

        $dailyMetricsRun = Run::create([
            'run_type' => 'compute_daily_metrics',
            'status' => 'completed',
            'started_at' => now('UTC')->subHours(2),
            'completed_at' => now('UTC')->subHours(2),
        ]);

        $olderRefineRun = Run::create([
            'run_type' => 'daily_refine',
            'status' => 'completed',
            'started_at' => now('UTC')->subHour(),
            'completed_at' => now('UTC')->subHour(),
        ]);

        $latestRefineRun = Run::create([
            'run_type' => 'daily_refine',
            'status' => 'completed',
            'started_at' => now('UTC')->subMinutes(30),
            'completed_at' => now('UTC')->subMinutes(30),
        ]);

        $aapl = Symbol::firstOrCreate(['symbol' => 'AAPL'], ['is_active' => true]);
        $msft = Symbol::firstOrCreate(['symbol' => 'MSFT'], ['is_active' => true]);
        $nvda = Symbol::firstOrCreate(['symbol' => 'NVDA'], ['is_active' => true]);

        foreach ([$aapl, $msft, $nvda] as $symbol) {
            MarketSnapshot::create([
                'run_id' => $dailyMetricsRun->id,
                'symbol_id' => $symbol->id,
                'snapshot_type' => 'derived_daily_metrics',
                'payload_json' => [
                    'metrics' => [
                        'breakout_level' => 185.0,
                        'support_level' => 178.0,
                        'trend_state' => 'uptrend',
                        'atr_percent' => 2.1,
                        'distance_to_breakout_percent' => 0.7,
                        'extension_percent' => 4.2,
                        'relative_volume_simple' => 1.1,
                    ],
                ],
                'created_at' => now('UTC')->subMinutes(25),
            ]);
        }

        MarketSnapshot::create([
            'run_id' => $dailyMetricsRun->id,
            'symbol_id' => $aapl->id,
            'snapshot_type' => 'intraday',
            'payload_json' => [
                'metrics' => [
                    'current_price' => 184.7,
                    'session_high' => 185.2,
                    'session_low' => 182.4,
                    'intraday_vwap' => 184.1,
                    'relative_volume_simple' => 1.3,
                    'market_state' => 'mixed',
                ],
            ],
            'created_at' => now('UTC')->subMinutes(5),
        ]);

        MarketSnapshot::create([
            'run_id' => $dailyMetricsRun->id,
            'symbol_id' => $msft->id,
            'snapshot_type' => 'intraday',
            'payload_json' => [
                'metrics' => [
                    'current_price' => 413.8,
                    'session_high' => 419.2,
                    'session_low' => 410.5,
                    'intraday_vwap' => 416.8,
                ],
            ],
            'created_at' => now('UTC')->subMinutes(5),
        ]);

        MarketSnapshot::create([
            'run_id' => $dailyMetricsRun->id,
            'symbol_id' => $nvda->id,
            'snapshot_type' => 'intraday',
            'payload_json' => [
                'metrics' => [
                    'current_price' => 189.0,
                    'session_high' => 189.5,
                    'session_low' => 184.9,
                    'intraday_vwap' => 187.8,
                ],
            ],
            'created_at' => now('UTC')->subMinutes(5),
        ]);

        WatchlistCandidate::create([
            'run_id' => $olderRefineRun->id,
            'symbol_id' => $aapl->id,
            'stage' => 'weekend',
            'status' => 'keep',
            'setup_type' => 'breakout',
            'trigger_band_low' => 184.0,
            'trigger_band_high' => 185.0,
            'created_at' => now('UTC')->subHour(),
        ]);

        $aaplCandidate = WatchlistCandidate::create([
            'run_id' => $latestRefineRun->id,
            'symbol_id' => $aapl->id,
            'stage' => 'weekend',
            'status' => 'keep',
            'setup_type' => 'breakout',
            'trigger_band_low' => 184.0,
            'trigger_band_high' => 185.0,
            'created_at' => now('UTC')->subMinutes(20),
        ]);

        $msftCandidate = WatchlistCandidate::create([
            'run_id' => $latestRefineRun->id,
            'symbol_id' => $msft->id,
            'stage' => 'weekend',
            'status' => 'wait',
            'setup_type' => 'pullback',
            'trigger_band_low' => 412.0,
            'trigger_band_high' => 414.0,
            'created_at' => now('UTC')->subMinutes(20),
        ]);

        $nvdaCandidate = WatchlistCandidate::create([
            'run_id' => $latestRefineRun->id,
            'symbol_id' => $nvda->id,
            'stage' => 'weekend',
            'status' => 'keep',
            'setup_type' => 'breakout',
            'trigger_band_low' => 184.0,
            'trigger_band_high' => 185.0,
            'created_at' => now('UTC')->subMinutes(20),
        ]);

        TradeSetup::create([
            'symbol_id' => $aapl->id,
            'source_candidate_id' => $aaplCandidate->id,
            'status' => 'open',
            'entry_price' => 184.5,
            'stop_price' => 182.5,
            'target1_price' => 188.0,
            'target2_price' => 190.0,
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl_789',
                'model' => 'gpt-4.1-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'validated_candidates' => [
                                    [
                                        'symbol' => 'AAPL',
                                        'decision' => 'enter_now',
                                        'entry_price' => 184.8,
                                        'stop_price' => 182.9,
                                        'target1_price' => 188.5,
                                        'target2_price' => 191.0,
                                        'already_extended' => false,
                                        'risk_note' => 'Do not chase if momentum spikes above session high.',
                                        'reasoning_text' => 'Price is inside trigger zone with supportive intraday structure.',
                                    ],
                                    [
                                        'symbol' => 'MSFT',
                                        'decision' => 'wait',
                                        'entry_price' => 413.2,
                                        'stop_price' => 409.8,
                                        'target1_price' => 418.0,
                                        'target2_price' => 421.5,
                                        'already_extended' => false,
                                        'risk_note' => 'Needs reclaim of trigger midpoint first.',
                                        'reasoning_text' => 'Near-band but not yet confirming continuation.',
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('prompt:intraday-validate')
            ->expectsOutput('Intraday validate prompt completed.')
            ->expectsOutputToContain('active candidates scanned: 3')
            ->expectsOutputToContain('candidates sent to model: 2')
            ->expectsOutputToContain('enter_now count: 1')
            ->expectsOutputToContain('wait count: 1')
            ->expectsOutputToContain('reject count: 0')
            ->expectsOutputToContain('trade setups created: 0')
            ->expectsOutputToContain('errors: 0')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $userContent = (string) data_get($payload, 'messages.1.content', '{}');
            $decoded = json_decode($userContent, true);
            $allowedSymbols = is_array($decoded) && is_array($decoded['allowed_symbols'] ?? null)
                ? $decoded['allowed_symbols']
                : [];

            return data_get($payload, 'response_format.json_schema.name') === 'intraday_entry_validator'
                && in_array('AAPL', $allowedSymbols, true)
                && ! in_array('NVDA', $allowedSymbols, true);
        });

        $run = Run::query()->where('run_type', 'intraday_validate')->latest('id')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(3, $run->meta_json['active_candidates_scanned']);
        $this->assertSame(2, $run->meta_json['candidates_sent_to_model']);

        $this->assertSame(1, PromptLog::query()->where('run_id', $run->id)->where('prompt_type', 'C')->count());

        $this->assertSame(1, TradeSetup::query()->where('symbol_id', $aapl->id)->where('status', 'open')->count());
        $this->assertSame(0, TradeSetup::query()->where('symbol_id', $aapl->id)->where('status', 'planned')->count());

        $this->assertSame(0, TradeSetup::query()->where('symbol_id', $msft->id)->where('status', 'planned')->count());
        $this->assertSame(0, TradeSetup::query()->where('symbol_id', $nvda->id)->where('status', 'planned')->count());

        $aaplCandidate->refresh();
        $msftCandidate->refresh();
        $nvdaCandidate->refresh();

        $this->assertSame('Price is inside trigger zone with supportive intraday structure.', $aaplCandidate->reasoning_text);
        $this->assertSame('Near-band but not yet confirming continuation.', $msftCandidate->reasoning_text);
        $this->assertNull($nvdaCandidate->reasoning_text);
    }

    public function test_it_uses_latest_keep_or_wait_candidate_per_symbol_for_intraday_filtering(): void
    {
        config()->set('services.intraday_validation.near_band_tolerance_percent', 0.75);
        config()->set('services.intraday_validation.max_extension_percent', 1.5);

        $dailyMetricsRun = Run::create([
            'run_type' => 'compute_daily_metrics',
            'status' => 'completed',
            'started_at' => now('UTC')->subHours(2),
            'completed_at' => now('UTC')->subHours(2),
        ]);

        $olderRefineRun = Run::create([
            'run_type' => 'daily_refine',
            'status' => 'completed',
            'started_at' => now('UTC')->subHour(),
            'completed_at' => now('UTC')->subHour(),
        ]);

        $latestRefineRun = Run::create([
            'run_type' => 'daily_refine',
            'status' => 'completed',
            'started_at' => now('UTC')->subMinutes(15),
            'completed_at' => now('UTC')->subMinutes(15),
        ]);

        $aapl = Symbol::firstOrCreate(['symbol' => 'AAPL'], ['is_active' => true]);

        MarketSnapshot::create([
            'run_id' => $dailyMetricsRun->id,
            'symbol_id' => $aapl->id,
            'snapshot_type' => 'derived_daily_metrics',
            'payload_json' => [
                'metrics' => [
                    'extension_percent' => 0.5,
                ],
            ],
            'created_at' => now('UTC')->subMinutes(10),
        ]);

        MarketSnapshot::create([
            'run_id' => $dailyMetricsRun->id,
            'symbol_id' => $aapl->id,
            'snapshot_type' => 'intraday',
            'payload_json' => [
                'metrics' => [
                    'current_price' => 100.0,
                ],
            ],
            'created_at' => now('UTC')->subMinutes(5),
        ]);

        WatchlistCandidate::create([
            'run_id' => $olderRefineRun->id,
            'symbol_id' => $aapl->id,
            'stage' => 'weekend',
            'status' => 'keep',
            'setup_type' => 'breakout',
            'trigger_band_low' => 99.5,
            'trigger_band_high' => 100.5,
            'created_at' => now('UTC')->subMinutes(50),
        ]);

        WatchlistCandidate::create([
            'run_id' => $latestRefineRun->id,
            'symbol_id' => $aapl->id,
            'stage' => 'weekend',
            'status' => 'wait',
            'setup_type' => 'pullback',
            'trigger_band_low' => 110.0,
            'trigger_band_high' => 111.0,
            'created_at' => now('UTC')->subMinutes(12),
        ]);

        Http::fake();

        $this->artisan('prompt:intraday-validate')
            ->expectsOutputToContain('active candidates scanned: 1')
            ->expectsOutputToContain('candidates sent to model: 0')
            ->expectsOutputToContain('AAPL skipped: price not near trigger band')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_reads_intraday_metrics_from_ingested_symbol_data_payload(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.model', 'gpt-4.1-mini');
        config()->set('services.intraday_validation.near_band_tolerance_percent', 0.75);
        config()->set('services.intraday_validation.max_extension_percent', 1.5);

        $dailyMetricsRun = Run::create([
            'run_type' => 'compute_daily_metrics',
            'status' => 'completed',
            'started_at' => now('UTC')->subHours(2),
            'completed_at' => now('UTC')->subHours(2),
        ]);

        $latestRefineRun = Run::create([
            'run_type' => 'daily_refine',
            'status' => 'completed',
            'started_at' => now('UTC')->subMinutes(15),
            'completed_at' => now('UTC')->subMinutes(15),
        ]);

        $aapl = Symbol::firstOrCreate(['symbol' => 'AAPL'], ['is_active' => true]);

        MarketSnapshot::create([
            'run_id' => $dailyMetricsRun->id,
            'symbol_id' => $aapl->id,
            'snapshot_type' => 'derived_daily_metrics',
            'payload_json' => [
                'metrics' => [
                    'breakout_level' => 185.0,
                    'support_level' => 178.0,
                    'trend_state' => 'uptrend',
                    'atr_percent' => 2.1,
                    'distance_to_breakout_percent' => 0.7,
                    'extension_percent' => 0.2,
                    'relative_volume_simple' => 1.1,
                ],
            ],
            'created_at' => now('UTC')->subMinutes(10),
        ]);

        MarketSnapshot::create([
            'run_id' => $dailyMetricsRun->id,
            'symbol_id' => $aapl->id,
            'snapshot_type' => 'intraday',
            'payload_json' => [
                'mode' => 'paper',
                'symbol_data' => [
                    'symbol' => 'AAPL',
                    'status' => 'ok',
                    'snapshot_type' => 'intraday',
                    'metrics' => [
                        'current_price' => 184.8,
                        'session_high' => 185.3,
                        'session_low' => 184.0,
                        'intraday_vwap' => 184.5,
                    ],
                ],
            ],
            'created_at' => now('UTC')->subMinutes(5),
        ]);

        WatchlistCandidate::create([
            'run_id' => $latestRefineRun->id,
            'symbol_id' => $aapl->id,
            'stage' => 'weekend',
            'status' => 'keep',
            'setup_type' => 'breakout',
            'trigger_band_low' => 184.0,
            'trigger_band_high' => 185.0,
            'created_at' => now('UTC')->subMinutes(12),
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl_790',
                'model' => 'gpt-4.1-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'validated_candidates' => [
                                    [
                                        'symbol' => 'AAPL',
                                        'decision' => 'wait',
                                        'entry_price' => 184.8,
                                        'stop_price' => 183.2,
                                        'target1_price' => 188.0,
                                        'target2_price' => 190.0,
                                        'already_extended' => false,
                                        'risk_note' => 'Wait for clean break.',
                                        'reasoning_text' => 'Valid intraday metrics were loaded.',
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('prompt:intraday-validate')
            ->expectsOutputToContain('active candidates scanned: 1')
            ->expectsOutputToContain('candidates sent to model: 1')
            ->doesntExpectOutputToContain('AAPL skipped: missing intraday price')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }
}

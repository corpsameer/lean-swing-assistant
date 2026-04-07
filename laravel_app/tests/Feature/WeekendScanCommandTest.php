<?php

namespace Tests\Feature;

use App\Models\MarketSnapshot;
use App\Models\Run;
use App\Models\Symbol;
use App\Models\WatchlistCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class WeekendScanCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver is not available in this environment.');
        }
    }

    public function test_it_stores_only_symbols_that_pass_all_weekend_filters(): void
    {
        $sourceRun = Run::create([
            'run_type' => 'compute_daily_metrics',
            'status' => 'completed',
            'started_at' => now('UTC')->subMinute(),
            'completed_at' => now('UTC')->subMinute(),
        ]);

        $passSymbol = Symbol::create(['symbol' => 'PASS', 'is_active' => true]);
        $failSymbol = Symbol::create(['symbol' => 'FAIL', 'is_active' => true]);

        MarketSnapshot::create([
            'run_id' => $sourceRun->id,
            'symbol_id' => $passSymbol->id,
            'snapshot_type' => 'derived_daily_metrics',
            'payload_json' => [
                'metrics' => [
                    'last_price' => 25,
                    'avg_volume_20d' => 2_500_000,
                    'atr_percent' => 3.4,
                    'extension_percent' => 20,
                    'distance_to_breakout_percent' => 1.5,
                    'trend_state' => 'uptrend',
                    'breakout_level' => 26,
                    'support_level' => 21,
                ],
            ],
            'created_at' => now('UTC')->subSeconds(30),
        ]);

        MarketSnapshot::create([
            'run_id' => $sourceRun->id,
            'symbol_id' => $failSymbol->id,
            'snapshot_type' => 'derived_daily_metrics',
            'payload_json' => [
                'metrics' => [
                    'last_price' => 4.5,
                    'avg_volume_20d' => 3_000_000,
                    'atr_percent' => 4,
                    'extension_percent' => 12,
                    'distance_to_breakout_percent' => 1,
                    'trend_state' => 'uptrend',
                    'breakout_level' => 6,
                    'support_level' => 5,
                ],
            ],
            'created_at' => now('UTC')->subSeconds(20),
        ]);

        $this->artisan('scan:weekend')
            ->expectsOutput('Weekend scan completed.')
            ->expectsOutputToContain('total scanned: 2')
            ->expectsOutputToContain('passed: 1')
            ->expectsOutputToContain('rejected: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('watchlist_candidates', 1);

        $candidate = WatchlistCandidate::query()->firstOrFail();
        $this->assertSame($passSymbol->id, $candidate->symbol_id);
        $this->assertSame('weekend', $candidate->stage);
        $this->assertSame('candidate', $candidate->status);
        $this->assertSame('breakout', $candidate->setup_type);
        $this->assertNull($candidate->score_total);
        $this->assertNull($candidate->prompt_output_json);
        $this->assertSame('26.0000', (string) $candidate->breakout_low_price);
        $this->assertSame('21.0000', (string) $candidate->support_low_price);

        $run = Run::query()->where('run_type', 'weekend_scan')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->meta_json['total_scanned']);
        $this->assertSame(1, $run->meta_json['passed']);
        $this->assertSame(1, $run->meta_json['rejected']);
    }
}

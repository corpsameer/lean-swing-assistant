<?php

namespace App\Console\Commands;

use App\Models\MarketSnapshot;
use App\Models\Run;
use App\Models\Symbol;
use App\Services\DailyMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

class ComputeDailyMetrics extends Command
{
    protected $signature = 'metrics:compute-daily
        {--symbol= : Optional symbol filter (e.g. AAPL)}
        {--limit= : Optional max symbol count to process}';

    protected $description = 'Compute deterministic daily metrics from latest ingested daily bar snapshots';

    public function handle(DailyMetricsService $metricsService): int
    {
        $symbolFilter = $this->option('symbol');
        $limit = $this->option('limit');

        $symbolQuery = Symbol::query()->orderBy('symbol');

        if (is_string($symbolFilter) && trim($symbolFilter) !== '') {
            $symbolQuery->where('symbol', strtoupper(trim($symbolFilter)));
        }

        if (is_numeric($limit) && (int) $limit > 0) {
            $symbolQuery->limit((int) $limit);
        }

        $symbols = $symbolQuery->get();

        $run = Run::create([
            'run_type' => 'compute_daily_metrics',
            'status' => 'running',
            'started_at' => now('UTC'),
            'meta_json' => [
                'symbol_filter' => is_string($symbolFilter) && trim($symbolFilter) !== '' ? strtoupper(trim($symbolFilter)) : null,
                'limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null,
            ],
        ]);

        $scanned = 0;
        $computed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($symbols as $symbol) {
            $scanned++;

            try {
                $dailySnapshot = MarketSnapshot::query()
                    ->where('symbol_id', $symbol->id)
                    ->where('snapshot_type', 'daily')
                    ->orderByDesc('id')
                    ->first();

                if ($dailySnapshot === null) {
                    $skipped++;
                    $this->line("SKIP {$symbol->symbol}: no daily snapshot found");

                    continue;
                }

                $symbolData = Arr::get($dailySnapshot->payload_json, 'symbol_data', []);
                $status = Arr::get($symbolData, 'status');

                if ($status !== 'ok') {
                    $skipped++;
                    $this->line("SKIP {$symbol->symbol}: source snapshot status is not ok");

                    continue;
                }

                $bars = Arr::get($symbolData, 'bars');
                if (! is_array($bars) || count($bars) < 50) {
                    $skipped++;
                    $this->line("SKIP {$symbol->symbol}: insufficient valid bars");

                    continue;
                }

                $metrics = $metricsService->compute($bars);

                MarketSnapshot::create([
                    'run_id' => $run->id,
                    'symbol_id' => $symbol->id,
                    'snapshot_type' => 'derived_daily_metrics',
                    'payload_json' => [
                        'computed_at_utc' => now('UTC')->toIso8601String(),
                        'source_snapshot_id' => $dailySnapshot->id,
                        'source_snapshot_type' => 'daily',
                        'bar_count' => count($bars),
                        'metrics' => $metrics,
                    ],
                    'created_at' => now('UTC'),
                ]);

                $computed++;
            } catch (Throwable $throwable) {
                $errors++;
                $this->error("ERROR {$symbol->symbol}: {$throwable->getMessage()}");
            }
        }

        $run->status = $errors > 0 ? 'completed_with_errors' : 'completed';
        $run->completed_at = now('UTC');
        $run->meta_json = array_merge($run->meta_json ?? [], [
            'symbols_scanned' => $scanned,
            'metrics_computed' => $computed,
            'skipped_count' => $skipped,
            'error_count' => $errors,
        ]);
        $run->save();

        $this->info('Daily metrics computation completed.');
        $this->line('symbols scanned: '.$scanned);
        $this->line('metrics computed: '.$computed);
        $this->line('skipped count: '.$skipped);
        $this->line('error count: '.$errors);

        return self::SUCCESS;
    }
}

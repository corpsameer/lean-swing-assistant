<?php

namespace App\Services;

use App\Models\MarketSnapshot;
use App\Models\Run;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class MarketDataIngestionService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    public function ingestDailyBarsPayload(array $payload, string $snapshotType): array
    {
        $this->validatePayload($payload);

        $fetchedAtUtc = CarbonImmutable::parse($payload['fetched_at_utc'])->utc();

        $run = Run::create([
            'run_type' => 'ingest_market_json',
            'status' => 'completed',
            'started_at' => now('UTC'),
            'completed_at' => now('UTC'),
            'meta_json' => [
                'mode' => $payload['mode'],
                'fetched_at_utc' => $fetchedAtUtc->toIso8601String(),
                'snapshot_type' => $snapshotType,
            ],
        ]);

        $total = 0;
        $successCount = 0;
        $errorCount = 0;
        $snapshotsStored = 0;

        foreach ($payload['symbols'] as $symbolPayload) {
            $total++;

            $symbolText = strtoupper((string) $symbolPayload['symbol']);
            $symbol = Symbol::firstOrCreate(
                ['symbol' => $symbolText],
                ['is_active' => true]
            );

            $status = (string) Arr::get($symbolPayload, 'status', 'unknown');
            if ($status === 'ok') {
                $successCount++;
            } else {
                $errorCount++;
            }

            MarketSnapshot::create([
                'run_id' => $run->id,
                'symbol_id' => $symbol->id,
                'snapshot_type' => $snapshotType,
                'payload_json' => [
                    'mode' => $payload['mode'],
                    'fetched_at_utc' => $fetchedAtUtc->toIso8601String(),
                    'symbol_data' => $symbolPayload,
                ],
                'created_at' => now('UTC'),
            ]);

            $snapshotsStored++;
        }

        return [
            'total_symbols_processed' => $total,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'snapshots_stored' => $snapshotsStored,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(array $payload): void
    {
        foreach (['mode', 'fetched_at_utc', 'symbols'] as $requiredField) {
            if (! array_key_exists($requiredField, $payload)) {
                throw new InvalidArgumentException("Missing required top-level field: {$requiredField}");
            }
        }

        if (! is_array($payload['symbols'])) {
            throw new InvalidArgumentException('Top-level field `symbols` must be an array.');
        }

        CarbonImmutable::parse($payload['fetched_at_utc']);

        foreach ($payload['symbols'] as $index => $symbolPayload) {
            if (! is_array($symbolPayload)) {
                throw new InvalidArgumentException("symbols[{$index}] must be an object.");
            }

            if (! array_key_exists('symbol', $symbolPayload)) {
                throw new InvalidArgumentException("symbols[{$index}] is missing required field: symbol");
            }

            if (! array_key_exists('status', $symbolPayload)) {
                throw new InvalidArgumentException("symbols[{$index}] is missing required field: status");
            }

            if (! in_array($symbolPayload['status'], ['ok', 'error'], true)) {
                throw new InvalidArgumentException("symbols[{$index}].status must be `ok` or `error`.");
            }
        }
    }
}

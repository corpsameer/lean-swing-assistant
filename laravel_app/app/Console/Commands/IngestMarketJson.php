<?php

namespace App\Console\Commands;

use App\Services\MarketDataIngestionService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

class IngestMarketJson extends Command
{
    protected $signature = 'market:ingest-json
        {path : JSON file path (absolute, relative, or storage/app-relative)}
        {--snapshot=daily : Snapshot type for stored market snapshots}';

    protected $description = 'Ingest Python-fetched market data JSON from disk into symbols and market_snapshots';

    public function handle(MarketDataIngestionService $ingestionService): int
    {
        $inputPath = (string) $this->argument('path');
        $snapshotType = (string) $this->option('snapshot');

        $resolvedPath = $this->resolvePath($inputPath);

        if ($resolvedPath === null) {
            $this->error("File not found for input path: {$inputPath}");
            $this->line('Checked:');
            $this->line('- '.$inputPath);
            $this->line('- '.base_path($inputPath));
            $this->line('- '.storage_path($inputPath));
            $this->line('- '.storage_path('app/'.$inputPath));

            return self::FAILURE;
        }

        try {
            $raw = file_get_contents($resolvedPath);
            if ($raw === false) {
                throw new InvalidArgumentException("Unable to read file: {$resolvedPath}");
            }

            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                throw new InvalidArgumentException('Payload must decode to a JSON object.');
            }

            $summary = $ingestionService->ingestDailyBarsPayload($payload, $snapshotType);
        } catch (JsonException $exception) {
            $this->error('Invalid JSON: '.$exception->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->error('Invalid payload: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Ingestion completed.');
        $this->line('file: '.$resolvedPath);
        $this->line('total symbols processed: '.$summary['total_symbols_processed']);
        $this->line('success count: '.$summary['success_count']);
        $this->line('error count: '.$summary['error_count']);
        $this->line('snapshots stored: '.$summary['snapshots_stored']);

        return self::SUCCESS;
    }

    private function resolvePath(string $inputPath): ?string
    {
        $candidates = [
            $inputPath,
            base_path($inputPath),
            storage_path($inputPath),
            storage_path('app/'.$inputPath),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

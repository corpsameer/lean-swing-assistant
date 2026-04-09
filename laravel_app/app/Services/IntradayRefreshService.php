<?php

namespace App\Services;

use App\Models\WatchlistCandidate;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class IntradayRefreshService
{
    public function __construct(private readonly MarketDataIngestionService $ingestionService) {}

    /**
     * @return array<int, string>
     */
    public function resolveActiveSymbols(): array
    {
        $latestIdsBySymbol = WatchlistCandidate::query()
            ->selectRaw('MAX(id) AS id')
            ->where('stage', 'weekend')
            ->whereIn('status', ['keep', 'wait'])
            ->groupBy('symbol_id');

        /** @var Collection<int, string> $symbols */
        $symbols = WatchlistCandidate::query()
            ->joinSub($latestIdsBySymbol, 'latest', function ($join): void {
                $join->on('watchlist_candidates.id', '=', 'latest.id');
            })
            ->join('symbols', 'symbols.id', '=', 'watchlist_candidates.symbol_id')
            ->where('symbols.is_active', true)
            ->orderBy('symbols.symbol')
            ->pluck('symbols.symbol')
            ->map(static fn ($symbol): string => strtoupper((string) $symbol));

        return $symbols->values()->all();
    }

    /**
     * @param  array<int, string>  $symbols
     */
    public function fetchForSymbols(array $symbols): string
    {
        if ($symbols === []) {
            throw new InvalidArgumentException('Active symbols list cannot be empty.');
        }

        $outputPath = $this->resolveOutputPath();
        $this->runPythonFetch($symbols, $outputPath);

        return $outputPath;
    }

    /**
     * @return array{snapshots_stored:int,total_symbols_processed:int,success_count:int,error_count:int}
     */
    public function ingestFromJsonPath(string $outputPath): array
    {
        $payload = $this->loadAndValidatePayload($outputPath);
        $summary = $this->ingestionService->ingestDailyBarsPayload($payload, 'intraday');

        return [
            'snapshots_stored' => (int) ($summary['snapshots_stored'] ?? 0),
            'total_symbols_processed' => (int) ($summary['total_symbols_processed'] ?? 0),
            'success_count' => (int) ($summary['success_count'] ?? 0),
            'error_count' => (int) ($summary['error_count'] ?? 0),
        ];
    }

    /**
     * @param  array<int, string>  $symbols
     */
    private function runPythonFetch(array $symbols, string $outputPath): void
    {
        $pythonExecutable = (string) config('services.intraday_fetch.python_executable', 'python');
        $scriptPath = (string) config('services.intraday_fetch.script_path', '');

        if ($scriptPath === '' || ! is_file($scriptPath)) {
            throw new RuntimeException('Intraday fetch script path is missing or invalid: '.$scriptPath);
        }

        $command = [$pythonExecutable, $scriptPath, ...$symbols, '--output', $outputPath];

        $process = new Process($command, base_path());
        $process->setTimeout((float) config('services.intraday_fetch.timeout_seconds', 180));
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            $stdOutput = trim($process->getOutput());
            $message = $errorOutput !== '' ? $errorOutput : $stdOutput;
            throw new RuntimeException('Intraday fetch failed: '.($message !== '' ? $message : 'unknown python process error'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadAndValidatePayload(string $outputPath): array
    {
        if (! is_file($outputPath)) {
            throw new RuntimeException('Intraday JSON output file is missing: '.$outputPath);
        }

        $raw = file_get_contents($outputPath);
        if ($raw === false) {
            throw new RuntimeException('Unable to read intraday JSON output: '.$outputPath);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Intraday JSON output is invalid: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($payload) || ! isset($payload['symbols']) || ! is_array($payload['symbols'])) {
            throw new RuntimeException('Intraday JSON output does not contain a valid symbols payload.');
        }

        return $payload;
    }

    private function resolveOutputPath(): string
    {
        $configuredPath = (string) config('services.intraday_fetch.output_path', storage_path('app/intraday_snapshot.json'));

        if ($configuredPath === '') {
            return storage_path('app/intraday_snapshot.json');
        }

        return str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            ? $configuredPath
            : base_path($configuredPath);
    }
}

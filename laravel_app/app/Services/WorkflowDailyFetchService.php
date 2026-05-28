<?php

namespace App\Services;

use App\Models\Symbol;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class WorkflowDailyFetchService
{
    public function resolvePythonExecutable(): string
    {
        $pythonExecutable = trim((string) env('EXECUTION_PYTHON_EXECUTABLE', 'python'));

        if ($pythonExecutable === '') {
            throw new InvalidArgumentException('EXECUTION_PYTHON_EXECUTABLE is missing. Set it to a valid Python executable (e.g. python or python3).');
        }

        return $pythonExecutable;
    }

    public function resolvePythonIbkrBasePath(): string
    {
        $pythonBasePath = trim((string) env('PYTHON_IBKR_BASE_PATH', '../python_ibkr'));
        if ($pythonBasePath === '') {
            throw new InvalidArgumentException('PYTHON_IBKR_BASE_PATH is missing. Set it to the python_ibkr project path (e.g. ../python_ibkr).');
        }

        return $this->isAbsolutePath($pythonBasePath)
            ? $pythonBasePath
            : base_path($pythonBasePath);
    }

    public function resolveSnapshotPath(): string
    {
        return storage_path('app/daily_snapshot.json');
    }

    /**
     * @return array<int, string>
     */
    public function resolveWorkflowSymbols(): array
    {
        $rawSymbols = trim((string) env('WORKFLOW_SYMBOLS', ''));

        if ($rawSymbols === '') {
            throw new InvalidArgumentException('WORKFLOW_SYMBOLS is missing. Add a comma-separated list, e.g. WORKFLOW_SYMBOLS=AAPL,MSFT,NVDA');
        }

        return $this->parseSymbolsCsv($rawSymbols, 'WORKFLOW_SYMBOLS');
    }

    /**
     * @return array{symbols:array<int,string>,source:string,total_available:int,max_symbols_applied:int|null}
     */
    public function resolveWorkflowSymbolsWithSource(?string $overrideSymbols = null, bool $forceWorkflowSymbolsFallback = false): array
    {
        if ($overrideSymbols !== null) {
            $symbols = $this->parseSymbolsCsv($overrideSymbols, '--symbols override');

            return ['symbols' => $symbols, 'source' => 'manual_override', 'total_available' => count($symbols), 'max_symbols_applied' => null];
        }

        if ($forceWorkflowSymbolsFallback) {
            $symbols = $this->resolveWorkflowSymbols();

            return ['symbols' => $symbols, 'source' => 'workflow_symbols', 'total_available' => count($symbols), 'max_symbols_applied' => null];
        }

        $cap = max(1, (int) env('UNIVERSE_MAX_SYMBOLS', (int) env('NASDAQ_UNIVERSE_MAX_SYMBOLS', 1000)));
        $recentDays = max(1, (int) env('UNIVERSE_RECENT_DAYS', 14));
        $hasLastSeenAt = Schema::hasColumn('symbols', 'last_seen_at');

        if ($hasLastSeenAt) {
            $recentScope = function ($query) use ($recentDays) {
                $query->where('last_seen_at', '>=', now()->subDays($recentDays));
            };
            $recentSymbols = $this->queryActiveSymbols($cap, $recentScope);
            if ($recentSymbols !== []) {
                return [
                    'symbols' => $recentSymbols,
                    'source' => sprintf('db_recent_%dd', $recentDays),
                    'total_available' => $this->countActiveSymbols($recentScope),
                    'max_symbols_applied' => $cap,
                ];
            }
        }

        $ibkrSymbols = $this->queryActiveSymbols($cap);
        if ($ibkrSymbols !== []) {
            return [
                'symbols' => $ibkrSymbols,
                'source' => 'db',
                'total_available' => $this->countActiveSymbols(),
                'max_symbols_applied' => $cap,
            ];
        }

        $symbols = $this->resolveWorkflowSymbols();

        return ['symbols' => $symbols, 'source' => 'workflow_symbols', 'total_available' => count($symbols), 'max_symbols_applied' => null];
    }

    /**
     * @param  null|callable(\Illuminate\Database\Eloquent\Builder):void  $scope
     * @return array<int, string>
     */
    private function queryActiveSymbols(int $cap, ?callable $scope = null): array
    {
        $symbolQuery = Symbol::query()->where('is_active', true);

        if ($scope !== null) {
            $scope($symbolQuery);
        }

        if (Schema::hasColumn('symbols', 'last_seen_at')) {
            $symbolQuery->orderByDesc('last_seen_at')->orderBy('symbol');
        } else {
            $symbolQuery->orderBy('symbol');
        }

        return $symbolQuery
            ->limit($cap)
            ->pluck('symbol')
            ->map(static fn (string $symbol): string => strtoupper(trim($symbol)))
            ->filter(static fn (string $symbol): bool => $symbol !== '')
            ->values()
            ->all();
    }

    /**
     * @param  null|callable(\Illuminate\Database\Eloquent\Builder):void  $scope
     */
    private function countActiveSymbols(?callable $scope = null): int
    {
        $symbolQuery = Symbol::query()->where('is_active', true);

        if ($scope !== null) {
            $scope($symbolQuery);
        }

        return $symbolQuery->count();
    }

    /**
     * @param  array<int,string>|null  $symbols
     */
    public function fetchDailyBarsToDefaultSnapshotPath(?array $symbols = null): string
    {
        $result = $this->fetchDailyBarsBatchedToDefaultSnapshotPath($symbols);

        return $result['snapshot_path'];
    }

    /**
     * @param  array<int,string>|null  $symbols
     * @param  null|callable(string,string):void  $output
     * @return array{snapshot_path:string,batch_count:int,symbols_requested:int,symbols_returned:int,valid_symbols:int,error_symbols:int,batches:array<int,array<string,mixed>>,stopped_early:bool,met_min_valid_symbols:bool,min_valid_symbols:int}
     */
    public function fetchDailyBarsBatchedToDefaultSnapshotPath(?array $symbols = null, ?callable $output = null): array
    {
        $symbols ??= $this->resolveWorkflowSymbols();
        $symbols = array_values(array_filter(array_map(static fn (string $symbol): string => strtoupper(trim($symbol)), $symbols)));

        if ($symbols === []) {
            throw new InvalidArgumentException('No symbols were provided for daily fetch.');
        }

        $outputPath = $this->resolveSnapshotPath();
        $partsDirectory = storage_path('app/daily_snapshot_parts');
        $pythonExecutable = $this->resolvePythonExecutable();
        $resolvedBasePath = $this->resolvePythonIbkrBasePath();
        $scriptPath = $resolvedBasePath.'/scripts/fetch_daily_bars.py';

        if (! is_file($scriptPath)) {
            throw new RuntimeException('Daily fetch script not found at: '.$scriptPath);
        }

        $batchSize = $this->resolvePositiveIntegerEnv('DAILY_FETCH_BATCH_SIZE', 100);
        $batchTimeoutSeconds = $this->resolvePositiveIntegerEnv('DAILY_FETCH_BATCH_TIMEOUT_SECONDS', 180);
        $maxTotalSeconds = $this->resolvePositiveIntegerEnv('DAILY_FETCH_MAX_TOTAL_SECONDS', 1800);
        $minValidSymbols = $this->resolvePositiveIntegerEnv('DAILY_FETCH_MIN_VALID_SYMBOLS', 1);
        $stopOnBatchFailure = $this->resolveBooleanEnv('DAILY_FETCH_STOP_ON_BATCH_FAILURE', false);

        $this->preparePartsDirectory($partsDirectory);

        $symbolBatches = array_chunk($symbols, $batchSize);
        $totalBatches = count($symbolBatches);
        $symbolsPayloads = [];
        $batchSummaries = [];
        $validSymbols = 0;
        $errorSymbols = 0;
        $stoppedEarly = false;
        $startedAt = microtime(true);
        $mode = 'paper';

        $this->emit($output, 'line', 'Daily fetch batch size: '.$batchSize);
        $this->emit($output, 'line', 'Daily fetch batch timeout seconds: '.$batchTimeoutSeconds);
        $this->emit($output, 'line', 'Daily fetch max total seconds: '.$maxTotalSeconds);
        $this->emit($output, 'line', 'Daily fetch minimum valid symbols: '.$minValidSymbols);
        $this->emit($output, 'line', 'Daily fetch stop on batch failure: '.($stopOnBatchFailure ? 'true' : 'false'));
        $this->emit($output, 'line', 'Total batches: '.$totalBatches);

        foreach ($symbolBatches as $batchIndex => $symbolBatch) {
            $elapsedBeforeBatch = (int) floor(microtime(true) - $startedAt);
            if ($elapsedBeforeBatch >= $maxTotalSeconds) {
                $stoppedEarly = true;
                $this->emit($output, 'warn', sprintf('Daily fetch total timeout reached after %d seconds; stopping additional batches.', $elapsedBeforeBatch));
                break;
            }

            $batchNumber = $batchIndex + 1;
            $batchOutputPath = $partsDirectory.'/'.sprintf('daily_snapshot_part_%03d.json', $batchNumber);
            $batchStartedAt = microtime(true);
            $this->emit($output, 'line', sprintf('Batch %d/%d started: %d symbols', $batchNumber, $totalBatches, count($symbolBatch)));

            $command = [$pythonExecutable, $scriptPath, ...$symbolBatch, '--output', $batchOutputPath];
            $process = new Process($command, base_path());
            $process->setTimeout((float) $batchTimeoutSeconds);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                $batchElapsed = (int) max(1, ceil(microtime(true) - $batchStartedAt));
                $batchSummaries[] = [
                    'batch' => $batchNumber,
                    'requested' => count($symbolBatch),
                    'returned' => 0,
                    'valid' => 0,
                    'errors' => count($symbolBatch),
                    'status' => 'timeout',
                    'elapsed_seconds' => $batchElapsed,
                ];
                $errorSymbols += count($symbolBatch);
                $this->emit($output, 'error', sprintf('Batch %d failed: timeout after %d seconds', $batchNumber, $batchTimeoutSeconds));

                if ($stopOnBatchFailure) {
                    $stoppedEarly = true;
                    break;
                }

                continue;
            }

            $batchElapsed = (int) max(0, round(microtime(true) - $batchStartedAt));

            if (! $process->isSuccessful()) {
                $message = trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : trim($process->getOutput());
                if ($message === '') {
                    $message = 'python process exited with code '.($process->getExitCode() ?? 'null');
                }

                $batchSummaries[] = [
                    'batch' => $batchNumber,
                    'requested' => count($symbolBatch),
                    'returned' => 0,
                    'valid' => 0,
                    'errors' => count($symbolBatch),
                    'status' => 'failed',
                    'elapsed_seconds' => $batchElapsed,
                    'error' => $message,
                ];
                $errorSymbols += count($symbolBatch);
                $this->emit($output, 'error', sprintf('Batch %d failed: %s', $batchNumber, $message));

                if ($stopOnBatchFailure) {
                    $stoppedEarly = true;
                    break;
                }

                continue;
            }

            if (! is_file($batchOutputPath)) {
                $message = 'Daily fetch completed but output file was not created: '.$batchOutputPath;
                $batchSummaries[] = [
                    'batch' => $batchNumber,
                    'requested' => count($symbolBatch),
                    'returned' => 0,
                    'valid' => 0,
                    'errors' => count($symbolBatch),
                    'status' => 'failed',
                    'elapsed_seconds' => $batchElapsed,
                    'error' => $message,
                ];
                $errorSymbols += count($symbolBatch);
                $this->emit($output, 'error', sprintf('Batch %d failed: %s', $batchNumber, $message));

                if ($stopOnBatchFailure) {
                    $stoppedEarly = true;
                    break;
                }

                continue;
            }

            try {
                $batchPayload = $this->readSnapshotPayload($batchOutputPath);
            } catch (RuntimeException $exception) {
                $batchSummaries[] = [
                    'batch' => $batchNumber,
                    'requested' => count($symbolBatch),
                    'returned' => 0,
                    'valid' => 0,
                    'errors' => count($symbolBatch),
                    'status' => 'failed',
                    'elapsed_seconds' => $batchElapsed,
                    'error' => $exception->getMessage(),
                ];
                $errorSymbols += count($symbolBatch);
                $this->emit($output, 'error', sprintf('Batch %d failed: %s', $batchNumber, $exception->getMessage()));

                if ($stopOnBatchFailure) {
                    $stoppedEarly = true;
                    break;
                }

                continue;
            }

            if (isset($batchPayload['mode']) && is_string($batchPayload['mode']) && trim($batchPayload['mode']) !== '') {
                $mode = $batchPayload['mode'];
            }

            if (! isset($batchPayload['symbols']) || ! is_array($batchPayload['symbols'])) {
                $message = 'Daily fetch output does not contain a valid symbols array: '.$batchOutputPath;
                $batchSummaries[] = [
                    'batch' => $batchNumber,
                    'requested' => count($symbolBatch),
                    'returned' => 0,
                    'valid' => 0,
                    'errors' => count($symbolBatch),
                    'status' => 'failed',
                    'elapsed_seconds' => $batchElapsed,
                    'error' => $message,
                ];
                $errorSymbols += count($symbolBatch);
                $this->emit($output, 'error', sprintf('Batch %d failed: %s', $batchNumber, $message));

                if ($stopOnBatchFailure) {
                    $stoppedEarly = true;
                    break;
                }

                continue;
            }

            $batchSymbols = $batchPayload['symbols'];

            $batchValid = 0;
            $batchErrors = 0;
            foreach ($batchSymbols as $symbolPayload) {
                if (! is_array($symbolPayload)) {
                    continue;
                }

                $normalizedSymbolPayload = $this->normalizeSymbolPayload($symbolPayload);
                if ($this->isValidSymbolPayload($normalizedSymbolPayload)) {
                    $batchValid++;
                } else {
                    $batchErrors++;
                }

                $symbolsPayloads[] = $normalizedSymbolPayload;
            }

            $batchErrors += max(0, count($symbolBatch) - count($batchSymbols));
            $validSymbols += $batchValid;
            $errorSymbols += $batchErrors;
            $batchSummaries[] = [
                'batch' => $batchNumber,
                'requested' => count($symbolBatch),
                'returned' => count($batchSymbols),
                'valid' => $batchValid,
                'errors' => $batchErrors,
                'status' => 'ok',
                'elapsed_seconds' => $batchElapsed,
            ];

            $this->emit($output, 'line', sprintf('Batch %d/%d completed: valid=%d errors=%d elapsed=%ds', $batchNumber, $totalBatches, $batchValid, $batchErrors, $batchElapsed));

            $elapsedAfterBatch = (int) floor(microtime(true) - $startedAt);
            if ($elapsedAfterBatch >= $maxTotalSeconds && $batchNumber < $totalBatches) {
                $stoppedEarly = true;
                $this->emit($output, 'warn', sprintf('Daily fetch total timeout reached after %d seconds; stopping additional batches.', $elapsedAfterBatch));
                break;
            }
        }

        $mergedPayload = [
            'mode' => $mode,
            'fetched_at_utc' => now()->utc()->toISOString(),
            'source' => 'batched_daily_fetch',
            'batch_count' => count($batchSummaries),
            'symbols_requested' => count($symbols),
            'symbols_returned' => count($symbolsPayloads),
            'valid_symbols' => $validSymbols,
            'error_symbols' => $errorSymbols,
            'batches' => $batchSummaries,
            'symbols' => $symbolsPayloads,
        ];

        file_put_contents($outputPath, json_encode($mergedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->emit($output, 'line', 'Merged daily snapshot: '.$outputPath);
        $this->emit($output, 'line', 'Valid symbols fetched: '.$validSymbols);
        $this->emit($output, 'line', 'Error symbols fetched: '.$errorSymbols);

        return [
            'snapshot_path' => $outputPath,
            'batch_count' => count($batchSummaries),
            'symbols_requested' => count($symbols),
            'symbols_returned' => count($symbolsPayloads),
            'valid_symbols' => $validSymbols,
            'error_symbols' => $errorSymbols,
            'batches' => $batchSummaries,
            'stopped_early' => $stoppedEarly,
            'met_min_valid_symbols' => $validSymbols >= $minValidSymbols,
            'min_valid_symbols' => $minValidSymbols,
        ];
    }

    public function countSuccessfulSymbolsFromSnapshot(string $snapshotPath): int
    {
        $payload = $this->readSnapshotPayload($snapshotPath);

        if (! is_array($payload) || ! isset($payload['symbols']) || ! is_array($payload['symbols'])) {
            throw new RuntimeException('Snapshot JSON does not contain a valid symbols array.');
        }

        $successCount = 0;
        foreach ($payload['symbols'] as $symbolPayload) {
            $bars = is_array($symbolPayload) ? ($symbolPayload['bars'] ?? null) : null;
            if (is_array($symbolPayload) && (($symbolPayload['status'] ?? null) === 'ok') && is_array($bars) && $bars !== []) {
                $successCount++;
            }
        }

        return $successCount;
    }

    /**
     * @return array<string,mixed>
     */
    private function readSnapshotPayload(string $snapshotPath): array
    {
        if (! is_file($snapshotPath)) {
            throw new RuntimeException('Snapshot file is missing: '.$snapshotPath);
        }

        $raw = file_get_contents($snapshotPath);
        if ($raw === false) {
            throw new RuntimeException('Unable to read snapshot file: '.$snapshotPath);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot JSON is invalid: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Snapshot JSON root is not an object.');
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $symbolPayload
     * @return array<string,mixed>
     */
    private function normalizeSymbolPayload(array $symbolPayload): array
    {
        $bars = $symbolPayload['bars'] ?? null;
        if (($symbolPayload['status'] ?? null) === 'ok' && (! is_array($bars) || $bars === [])) {
            $symbolPayload['status'] = 'error';
            $symbolPayload['error'] = ($symbolPayload['error'] ?? null) ?: 'No daily bars returned.';
            $symbolPayload['bars'] = is_array($bars) ? $bars : [];
        }

        return $symbolPayload;
    }

    /**
     * @param  array<string,mixed>  $symbolPayload
     */
    private function isValidSymbolPayload(array $symbolPayload): bool
    {
        $bars = $symbolPayload['bars'] ?? null;

        return ($symbolPayload['status'] ?? null) === 'ok' && is_array($bars) && $bars !== [];
    }

    private function resolvePositiveIntegerEnv(string $key, int $default): int
    {
        $rawValue = env($key, $default);
        if (is_int($rawValue)) {
            return $rawValue > 0 ? $rawValue : $default;
        }

        $normalizedValue = trim((string) $rawValue);
        if ($normalizedValue === '' || filter_var($normalizedValue, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        $value = (int) $normalizedValue;

        return $value > 0 ? $value : $default;
    }

    private function resolveBooleanEnv(string $key, bool $default): bool
    {
        $rawValue = env($key, $default);
        if (is_bool($rawValue)) {
            return $rawValue;
        }

        $parsedValue = filter_var($rawValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsedValue ?? $default;
    }

    private function preparePartsDirectory(string $partsDirectory): void
    {
        if (! is_dir($partsDirectory) && ! mkdir($partsDirectory, 0755, true) && ! is_dir($partsDirectory)) {
            throw new RuntimeException('Unable to create daily snapshot parts directory: '.$partsDirectory);
        }

        foreach (glob($partsDirectory.'/daily_snapshot_part_*.json') ?: [] as $partFile) {
            if (is_file($partFile)) {
                unlink($partFile);
            }
        }
    }

    /**
     * @param  null|callable(string,string):void  $output
     */
    private function emit(?callable $output, string $level, string $message): void
    {
        if ($output !== null) {
            $output($level, $message);
        }
    }

    /**
     * @return array<int, string>
     */
    private function parseSymbolsCsv(string $rawSymbols, string $sourceLabel): array
    {
        $symbols = collect(explode(',', $rawSymbols))
            ->map(static fn (string $symbol): string => strtoupper(trim($symbol)))
            ->filter(static fn (string $symbol): bool => $symbol !== '')
            ->values()
            ->all();

        if ($symbols === []) {
            throw new InvalidArgumentException($sourceLabel.' is empty after parsing. Add at least one symbol.');
        }

        return $symbols;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || str_starts_with($path, '\\\\')) {
            return true;
        }

        return Str::match('/^[A-Za-z]:[\\\\\/]/', $path) !== '';
    }
}

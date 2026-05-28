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
     * @return array{snapshot_path:string,batch_count:int,symbols_requested:int,symbols_returned:int,valid_symbols:int,error_symbols:int,failed_batches:int,failed_symbols:int,failed_symbols_preview:array<int,string>,partial:bool,batches:array<int,array<string,mixed>>,stopped_early:bool,met_min_valid_symbols:bool,min_valid_symbols:int}
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

        $batchSize = $this->resolvePositiveIntegerEnv('DAILY_FETCH_BATCH_SIZE', 25);
        $batchTimeoutSeconds = $this->resolvePositiveIntegerEnv('DAILY_FETCH_BATCH_TIMEOUT_SECONDS', 240);
        $maxTotalSeconds = $this->resolvePositiveIntegerEnv('DAILY_FETCH_MAX_TOTAL_SECONDS', 3600);
        $minValidSymbols = $this->resolvePositiveIntegerEnv('DAILY_FETCH_MIN_VALID_SYMBOLS', 1);
        $stopOnBatchFailure = $this->resolveBooleanEnv('DAILY_FETCH_STOP_ON_BATCH_FAILURE', false);
        $retryFailedBatches = $this->resolveBooleanEnv('DAILY_FETCH_RETRY_FAILED_BATCHES', true);
        $retryBatchSize = $this->resolvePositiveIntegerEnv('DAILY_FETCH_RETRY_BATCH_SIZE', 10);
        $maxBatchRetries = $this->resolvePositiveIntegerEnv('DAILY_FETCH_MAX_BATCH_RETRIES', 1);
        $skipExistingParts = $this->resolveBooleanEnv('DAILY_FETCH_SKIP_EXISTING_PARTS', true);
        $clearPartsBeforeRun = $this->resolveBooleanEnv('DAILY_FETCH_CLEAR_PARTS_BEFORE_RUN', true);

        $clearedParts = $this->preparePartsDirectory($partsDirectory, $clearPartsBeforeRun);
        if ($clearPartsBeforeRun) {
            $this->emit($output, 'line', 'Cleared old daily snapshot part files: '.$clearedParts);
        } elseif ($skipExistingParts) {
            $this->emit($output, 'line', 'Daily fetch resume mode: existing valid part files will be reused.');
        }

        $symbolBatches = array_chunk($symbols, $batchSize);
        $totalBatches = count($symbolBatches);
        $symbolsPayloads = [];
        $batchSummaries = [];
        $validSymbols = 0;
        $payloadErrorSymbols = 0;
        $failedSymbols = [];
        $failedBatchCount = 0;
        $stoppedEarly = false;
        $hitTotalTimeout = false;
        $startedAt = microtime(true);
        $mode = 'paper';

        $this->emit($output, 'line', 'Daily fetch batch size: '.$batchSize);
        $this->emit($output, 'line', 'Daily fetch batch timeout seconds: '.$batchTimeoutSeconds);
        $this->emit($output, 'line', 'Daily fetch max total seconds: '.$maxTotalSeconds);
        $this->emit($output, 'line', 'Daily fetch minimum valid symbols: '.$minValidSymbols);
        $this->emit($output, 'line', 'Daily fetch stop on batch failure: '.($stopOnBatchFailure ? 'true' : 'false'));
        $this->emit($output, 'line', 'Daily fetch retry failed batches: '.($retryFailedBatches ? 'true' : 'false'));
        $this->emit($output, 'line', 'Daily fetch retry batch size: '.$retryBatchSize);
        $this->emit($output, 'line', 'Total batches: '.$totalBatches);

        foreach ($symbolBatches as $batchIndex => $symbolBatch) {
            if ($this->hasExceededTotalFetchSeconds($startedAt, $maxTotalSeconds)) {
                $stoppedEarly = true;
                $hitTotalTimeout = true;
                $this->emit($output, 'warn', 'Daily fetch max total time reached. Proceeding with partial data if valid threshold is met.');
                $this->addFailedSymbols($failedSymbols, array_merge(...array_slice($symbolBatches, $batchIndex)));
                break;
            }

            $batchNumber = $batchIndex + 1;
            $batchOutputPath = $partsDirectory.'/'.sprintf('daily_snapshot_part_%03d.json', $batchNumber);
            $this->emit($output, 'line', sprintf('Batch %d/%d started: %d symbols', $batchNumber, $totalBatches, count($symbolBatch)));

            $attempt = $this->runDailyFetchAttempt(
                label: sprintf('Batch %d', $batchNumber),
                symbols: $symbolBatch,
                outputPath: $batchOutputPath,
                pythonExecutable: $pythonExecutable,
                scriptPath: $scriptPath,
                timeoutSeconds: $batchTimeoutSeconds,
                skipExistingParts: $skipExistingParts,
                output: $output,
            );
            $batchSummaries[] = $attempt['summary'];

            if ($attempt['status'] === 'ok' || $attempt['status'] === 'reused') {
                $this->mergeSuccessfulAttemptPayload($attempt['payload'], $symbolsPayloads, $validSymbols, $payloadErrorSymbols, $mode);
                $this->emit($output, 'line', sprintf('Batch %d/%d completed: valid=%d errors=%d elapsed=%ds', $batchNumber, $totalBatches, $attempt['summary']['valid'], $attempt['summary']['errors'], $attempt['summary']['elapsed_seconds']));
                continue;
            }

            if ($attempt['status'] === 'timeout' && $retryFailedBatches && $maxBatchRetries > 0) {
                $this->emit($output, 'warn', sprintf('Batch %d will be retried with retry batch size %d', $batchNumber, $retryBatchSize));

                $retryChunks = array_chunk($symbolBatch, $retryBatchSize);
                $batchRetryFailedSymbols = [];
                for ($retryRound = 1; $retryRound <= $maxBatchRetries; $retryRound++) {
                    $batchRetryFailedSymbols = [];

                    foreach ($retryChunks as $retryIndex => $retrySymbols) {
                        if ($this->hasExceededTotalFetchSeconds($startedAt, $maxTotalSeconds)) {
                            $stoppedEarly = true;
                            $hitTotalTimeout = true;
                            $this->emit($output, 'warn', 'Daily fetch max total time reached. Proceeding with partial data if valid threshold is met.');
                            $remainingRetryChunks = array_slice($retryChunks, $retryIndex);
                            foreach ($remainingRetryChunks as $remainingRetrySymbols) {
                                $this->addFailedSymbols($failedSymbols, $remainingRetrySymbols);
                            }
                            break 2;
                        }

                        $retryNumber = $retryIndex + 1;
                        $retryOutputPath = $partsDirectory.'/'.sprintf('daily_snapshot_part_%03d_retry_%03d.json', $batchNumber, $retryNumber);
                        $this->emit($output, 'line', sprintf('Batch %d retry %d/%d: %d symbols', $batchNumber, $retryNumber, count($retryChunks), count($retrySymbols)));

                        $retryAttempt = $this->runDailyFetchAttempt(
                            label: sprintf('Batch %d retry %d/%d', $batchNumber, $retryNumber, count($retryChunks)),
                            symbols: $retrySymbols,
                            outputPath: $retryOutputPath,
                            pythonExecutable: $pythonExecutable,
                            scriptPath: $scriptPath,
                            timeoutSeconds: $batchTimeoutSeconds,
                            skipExistingParts: $skipExistingParts,
                            output: $output,
                        );
                        $batchSummaries[] = $retryAttempt['summary'];

                        if ($retryAttempt['status'] === 'ok' || $retryAttempt['status'] === 'reused') {
                            $this->mergeSuccessfulAttemptPayload($retryAttempt['payload'], $symbolsPayloads, $validSymbols, $payloadErrorSymbols, $mode);
                            $this->emit($output, 'line', sprintf('Batch %d retry %d/%d completed: valid=%d errors=%d elapsed=%ds', $batchNumber, $retryNumber, count($retryChunks), $retryAttempt['summary']['valid'], $retryAttempt['summary']['errors'], $retryAttempt['summary']['elapsed_seconds']));
                        } else {
                            $batchRetryFailedSymbols = array_merge($batchRetryFailedSymbols, $retrySymbols);
                        }
                    }

                    if ($batchRetryFailedSymbols === [] || $retryRound >= $maxBatchRetries) {
                        break;
                    }

                    $retryChunks = array_chunk($batchRetryFailedSymbols, $retryBatchSize);
                }

                if ($batchRetryFailedSymbols !== []) {
                    $failedBatchCount += count(array_chunk($batchRetryFailedSymbols, $retryBatchSize));
                    $this->addFailedSymbols($failedSymbols, $batchRetryFailedSymbols);

                    if ($stopOnBatchFailure) {
                        $stoppedEarly = true;
                        break;
                    }
                }

                if ($stoppedEarly) {
                    break;
                }

                continue;
            }

            $failedBatchCount++;
            $this->addFailedSymbols($failedSymbols, $symbolBatch);

            if ($stopOnBatchFailure) {
                $stoppedEarly = true;
                break;
            }
        }

        $failedSymbols = array_values(array_unique($failedSymbols));
        $errorSymbols = $payloadErrorSymbols + count($failedSymbols);
        $partial = $failedSymbols !== [] || $failedBatchCount > 0 || $hitTotalTimeout;
        $failedSymbolsPreview = array_slice($failedSymbols, 0, 20);

        $mergedPayload = [
            'mode' => $mode,
            'fetched_at_utc' => now()->utc()->toISOString(),
            'source' => 'batched_daily_fetch',
            'batch_count' => count($batchSummaries),
            'symbols_requested' => count($symbols),
            'symbols_returned' => count($symbolsPayloads),
            'valid_symbols' => $validSymbols,
            'error_symbols' => $errorSymbols,
            'failed_batches' => $failedBatchCount,
            'failed_symbols' => count($failedSymbols),
            'failed_symbols_preview' => $failedSymbolsPreview,
            'partial' => $partial,
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
            'failed_batches' => $failedBatchCount,
            'failed_symbols' => count($failedSymbols),
            'failed_symbols_preview' => $failedSymbolsPreview,
            'partial' => $partial,
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
     * @param  array<int,string>  $symbols
     * @param  null|callable(string,string):void  $output
     * @return array{status:string,summary:array<string,mixed>,payload:array<string,mixed>|null}
     */
    private function runDailyFetchAttempt(
        string $label,
        array $symbols,
        string $outputPath,
        string $pythonExecutable,
        string $scriptPath,
        int $timeoutSeconds,
        bool $skipExistingParts,
        ?callable $output,
    ): array {
        $startedAt = microtime(true);
        $baseSummary = [
            'batch' => $this->batchNumberFromLabel($label),
            'label' => $label,
            'requested' => count($symbols),
            'returned' => 0,
            'valid' => 0,
            'errors' => count($symbols),
            'status' => 'failed',
            'elapsed_seconds' => 0,
            'symbols_preview' => $this->formatSymbolsPreview($symbols, 10),
            'output_path' => $outputPath,
            'timeout_seconds' => $timeoutSeconds,
        ];

        if ($skipExistingParts && is_file($outputPath)) {
            try {
                $payload = $this->readSnapshotPayload($outputPath);
                if (! isset($payload['symbols']) || ! is_array($payload['symbols'])) {
                    throw new RuntimeException('existing part JSON does not contain a valid symbols array');
                }

                $summary = $this->summarizePayloadSymbols($payload['symbols'], count($symbols));
                $summary = array_merge($baseSummary, $summary, [
                    'status' => 'reused',
                    'elapsed_seconds' => 0,
                ]);

                $this->emit($output, 'line', sprintf('%s reused existing part: valid=%d errors=%d path=%s', $label, $summary['valid'], $summary['errors'], $outputPath));

                return ['status' => 'reused', 'summary' => $summary, 'payload' => $payload];
            } catch (RuntimeException $exception) {
                $this->emit($output, 'warn', sprintf('%s existing part ignored: %s', $label, $exception->getMessage()));
            }
        }

        $process = new Process([$pythonExecutable, $scriptPath, ...$symbols, '--output', $outputPath], base_path());
        $process->setTimeout((float) $timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $summary = array_merge($baseSummary, [
                'status' => 'timeout',
                'elapsed_seconds' => (int) max(1, ceil(microtime(true) - $startedAt)),
                'error' => sprintf('timeout after %d seconds', $timeoutSeconds),
            ]);
            $this->emit($output, 'error', sprintf('%s failed: timeout after %d seconds (symbols=%d preview=%s output=%s)', $label, $timeoutSeconds, count($symbols), $summary['symbols_preview'], $outputPath));

            return ['status' => 'timeout', 'summary' => $summary, 'payload' => null];
        }

        $elapsedSeconds = (int) max(0, round(microtime(true) - $startedAt));

        if (! $process->isSuccessful()) {
            $message = $this->summarizeProcessFailure($process);
            $summary = array_merge($baseSummary, [
                'status' => 'failed',
                'elapsed_seconds' => $elapsedSeconds,
                'error' => $message,
            ]);
            $this->emit($output, 'error', sprintf('%s failed: %s (symbols=%d preview=%s output=%s timeout=%ds)', $label, $message, count($symbols), $summary['symbols_preview'], $outputPath, $timeoutSeconds));

            return ['status' => 'failed', 'summary' => $summary, 'payload' => null];
        }

        if (! is_file($outputPath)) {
            $message = 'output file was not created';
            $summary = array_merge($baseSummary, [
                'status' => 'failed',
                'elapsed_seconds' => $elapsedSeconds,
                'error' => $message,
            ]);
            $this->emit($output, 'error', sprintf('%s failed: %s (symbols=%d preview=%s output=%s timeout=%ds)', $label, $message, count($symbols), $summary['symbols_preview'], $outputPath, $timeoutSeconds));

            return ['status' => 'failed', 'summary' => $summary, 'payload' => null];
        }

        try {
            $payload = $this->readSnapshotPayload($outputPath);
        } catch (RuntimeException $exception) {
            $summary = array_merge($baseSummary, [
                'status' => 'failed',
                'elapsed_seconds' => $elapsedSeconds,
                'error' => $exception->getMessage(),
            ]);
            $this->emit($output, 'error', sprintf('%s failed: %s (symbols=%d preview=%s output=%s timeout=%ds)', $label, $exception->getMessage(), count($symbols), $summary['symbols_preview'], $outputPath, $timeoutSeconds));

            return ['status' => 'failed', 'summary' => $summary, 'payload' => null];
        }

        if (! isset($payload['symbols']) || ! is_array($payload['symbols'])) {
            $message = 'output JSON does not contain a valid symbols array';
            $summary = array_merge($baseSummary, [
                'status' => 'failed',
                'elapsed_seconds' => $elapsedSeconds,
                'error' => $message,
            ]);
            $this->emit($output, 'error', sprintf('%s failed: %s (symbols=%d preview=%s output=%s timeout=%ds)', $label, $message, count($symbols), $summary['symbols_preview'], $outputPath, $timeoutSeconds));

            return ['status' => 'failed', 'summary' => $summary, 'payload' => null];
        }

        $summary = $this->summarizePayloadSymbols($payload['symbols'], count($symbols));
        $summary = array_merge($baseSummary, $summary, [
            'status' => 'ok',
            'elapsed_seconds' => $elapsedSeconds,
        ]);

        return ['status' => 'ok', 'summary' => $summary, 'payload' => $payload];
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @param  array<int,array<string,mixed>>  $symbolsPayloads
     */
    private function mergeSuccessfulAttemptPayload(?array $payload, array &$symbolsPayloads, int &$validSymbols, int &$payloadErrorSymbols, string &$mode): void
    {
        if ($payload === null) {
            return;
        }

        if (isset($payload['mode']) && is_string($payload['mode']) && trim($payload['mode']) !== '') {
            $mode = $payload['mode'];
        }

        $batchSymbols = isset($payload['symbols']) && is_array($payload['symbols']) ? $payload['symbols'] : [];
        foreach ($batchSymbols as $symbolPayload) {
            if (! is_array($symbolPayload)) {
                continue;
            }

            $normalizedSymbolPayload = $this->normalizeSymbolPayload($symbolPayload);
            if ($this->isValidSymbolPayload($normalizedSymbolPayload)) {
                $validSymbols++;
            } else {
                $payloadErrorSymbols++;
            }

            $symbolsPayloads[] = $normalizedSymbolPayload;
        }
    }

    /**
     * @param  array<int,mixed>  $payloadSymbols
     * @return array{returned:int,valid:int,errors:int}
     */
    private function summarizePayloadSymbols(array $payloadSymbols, int $requested): array
    {
        $valid = 0;
        $errors = 0;

        foreach ($payloadSymbols as $symbolPayload) {
            if (! is_array($symbolPayload)) {
                $errors++;
                continue;
            }

            if ($this->isValidSymbolPayload($this->normalizeSymbolPayload($symbolPayload))) {
                $valid++;
            } else {
                $errors++;
            }
        }

        return [
            'returned' => count($payloadSymbols),
            'valid' => $valid,
            'errors' => $errors + max(0, $requested - count($payloadSymbols)),
        ];
    }

    private function summarizeProcessFailure(Process $process): string
    {
        $message = trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : trim($process->getOutput());
        if ($message === '') {
            $message = 'python process exited with code '.($process->getExitCode() ?? 'null');
        }

        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return Str::limit($message, 300);
    }

    private function batchNumberFromLabel(string $label): int
    {
        if (preg_match('/Batch\s+(\d+)/', $label, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * @param  array<int,string>  $symbols
     */
    private function addFailedSymbols(array &$failedSymbols, array $symbols): void
    {
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));
            if ($symbol !== '') {
                $failedSymbols[] = $symbol;
            }
        }
    }

    /**
     * @param  array<int,string>  $symbols
     */
    private function formatSymbolsPreview(array $symbols, int $limit = 20): string
    {
        $preview = array_slice($symbols, 0, $limit);
        $remaining = max(0, count($symbols) - count($preview));
        $formatted = implode(', ', $preview);

        if ($remaining > 0) {
            $formatted .= ', ... (+'.$remaining.' more)';
        }

        return $formatted;
    }

    private function hasExceededTotalFetchSeconds(float $startedAt, int $maxTotalSeconds): bool
    {
        return (int) floor(microtime(true) - $startedAt) >= $maxTotalSeconds;
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

    private function preparePartsDirectory(string $partsDirectory, bool $clearPartsBeforeRun): int
    {
        if (! is_dir($partsDirectory) && ! mkdir($partsDirectory, 0755, true) && ! is_dir($partsDirectory)) {
            throw new RuntimeException('Unable to create daily snapshot parts directory: '.$partsDirectory);
        }

        if (! $clearPartsBeforeRun) {
            return 0;
        }

        $clearedParts = 0;
        foreach (glob($partsDirectory.'/daily_snapshot_part_*.json') ?: [] as $partFile) {
            if (is_file($partFile)) {
                unlink($partFile);
                $clearedParts++;
            }
        }

        return $clearedParts;
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

<?php

namespace App\Services;

use App\Models\Symbol;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
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
     * @return array{symbols:array<int,string>,source:string}
     */
    public function resolveWorkflowSymbolsWithSource(?string $overrideSymbols = null, bool $forceWorkflowSymbolsFallback = false): array
    {
        if ($overrideSymbols !== null) {
            $symbols = $this->parseSymbolsCsv($overrideSymbols, '--symbols override');

            return ['symbols' => $symbols, 'source' => 'manual_override'];
        }

        if ($forceWorkflowSymbolsFallback) {
            return ['symbols' => $this->resolveWorkflowSymbols(), 'source' => 'workflow_symbols'];
        }

        $cap = max(1, (int) env('UNIVERSE_MAX_SYMBOLS', (int) env('NASDAQ_UNIVERSE_MAX_SYMBOLS', 1000)));
        $recentDays = max(1, (int) env('UNIVERSE_RECENT_DAYS', 14));
        $hasLastSeenAt = Schema::hasColumn('symbols', 'last_seen_at');

        if ($hasLastSeenAt) {
            $recentSymbols = $this->queryActiveSymbols($cap, function ($query) use ($recentDays) {
                $query->where('last_seen_at', '>=', now()->subDays($recentDays));
            });
            if ($recentSymbols !== []) {
                return ['symbols' => $recentSymbols, 'source' => sprintf('db_recent_%dd', $recentDays)];
            }
        }

        $ibkrSymbols = $this->queryActiveSymbols($cap);
        if ($ibkrSymbols !== []) {
            return ['symbols' => $ibkrSymbols, 'source' => 'db'];
        }

        return ['symbols' => $this->resolveWorkflowSymbols(), 'source' => 'workflow_symbols'];
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
     * @param  array<int,string>|null  $symbols
     */
    public function fetchDailyBarsToDefaultSnapshotPath(?array $symbols = null): string
    {
        $symbols ??= $this->resolveWorkflowSymbols();
        $outputPath = $this->resolveSnapshotPath();
        $pythonExecutable = $this->resolvePythonExecutable();
        $resolvedBasePath = $this->resolvePythonIbkrBasePath();

        $scriptPath = $resolvedBasePath.'/scripts/fetch_daily_bars.py';

        if (! is_file($scriptPath)) {
            throw new RuntimeException('Daily fetch script not found at: '.$scriptPath);
        }

        $command = [$pythonExecutable, $scriptPath, ...$symbols, '--output', $outputPath];
        $process = new Process($command, base_path());
        $process->setTimeout(180.0);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            $stdOutput = trim($process->getOutput());
            $message = $errorOutput !== '' ? $errorOutput : $stdOutput;
            $commandLine = $process->getCommandLine();
            $exitCode = $process->getExitCode();
            $timedOut = $process->isTimedOut();

            if ($message === '') {
                $message = sprintf(
                    'python process exited without output (exit_code=%s, timed_out=%s, command=%s)',
                    $exitCode !== null ? (string) $exitCode : 'null',
                    $timedOut ? 'yes' : 'no',
                    $commandLine
                );
            }

            throw new RuntimeException('Daily fetch failed: '.$message);
        }

        if (! is_file($outputPath)) {
            throw new RuntimeException('Daily fetch completed but output file was not created: '.$outputPath);
        }

        return $outputPath;
    }

    public function countSuccessfulSymbolsFromSnapshot(string $snapshotPath): int
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

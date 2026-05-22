<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class WorkflowDailyFetchService
{
    /**
     * @return array<int, string>
     */
    public function resolveWorkflowSymbols(): array
    {
        $rawSymbols = trim((string) env('WORKFLOW_SYMBOLS', ''));

        if ($rawSymbols === '') {
            throw new InvalidArgumentException('WORKFLOW_SYMBOLS is missing. Add a comma-separated list, e.g. WORKFLOW_SYMBOLS=AAPL,MSFT,NVDA');
        }

        $symbols = collect(explode(',', $rawSymbols))
            ->map(static fn (string $symbol): string => strtoupper(trim($symbol)))
            ->filter(static fn (string $symbol): bool => $symbol !== '')
            ->values()
            ->all();

        if ($symbols === []) {
            throw new InvalidArgumentException('WORKFLOW_SYMBOLS is empty after parsing. Add at least one symbol.');
        }

        return $symbols;
    }

    public function fetchDailyBarsToDefaultSnapshotPath(): string
    {
        $symbols = $this->resolveWorkflowSymbols();
        $outputPath = storage_path('app/daily_snapshot.json');

        $pythonExecutable = trim((string) env('EXECUTION_PYTHON_EXECUTABLE', 'python'));
        if ($pythonExecutable === '') {
            throw new InvalidArgumentException('EXECUTION_PYTHON_EXECUTABLE is missing. Set it to a valid Python executable (e.g. python or python3).');
        }

        $pythonBasePath = trim((string) env('PYTHON_IBKR_BASE_PATH', '../python_ibkr'));
        if ($pythonBasePath === '') {
            throw new InvalidArgumentException('PYTHON_IBKR_BASE_PATH is missing. Set it to the python_ibkr project path (e.g. ../python_ibkr).');
        }

        $resolvedBasePath = $this->isAbsolutePath($pythonBasePath)
            ? $pythonBasePath
            : base_path($pythonBasePath);

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

            throw new RuntimeException('Daily fetch failed: '.($message !== '' ? $message : 'unknown python process error'));
        }

        if (! is_file($outputPath)) {
            throw new RuntimeException('Daily fetch completed but output file was not created: '.$outputPath);
        }

        return $outputPath;
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

<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class IbkrHealthService
{
    public function __construct(private readonly WorkflowDailyFetchService $fetchService) {}

    /**
     * @return array{ok:bool,message:string,stdout:string,stderr:string,exit_code:int|null}
     */
    public function check(int $timeoutSeconds = 20): array
    {
        try {
            $pythonExecutable = $this->fetchService->resolvePythonExecutable();
            $pythonBasePath = $this->fetchService->resolvePythonIbkrBasePath();
        } catch (\Throwable $throwable) {
            return $this->errorResult($throwable->getMessage());
        }

        $scriptPath = $pythonBasePath.'/scripts/health_check.py';
        if (! is_dir($pythonBasePath)) {
            return $this->errorResult('Python IBKR base path does not exist: '.$pythonBasePath);
        }
        if (! is_file($scriptPath)) {
            return $this->errorResult('IBKR health script not found: '.$scriptPath);
        }

        $process = new Process([$pythonExecutable, $scriptPath], base_path());
        $process->setTimeout((float) $timeoutSeconds);
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $combined = trim($stderr !== '' ? $stderr."\n".$stdout : $stdout);

        if (! $process->isSuccessful()) {
            return [
                'ok' => false,
                'message' => $combined !== '' ? $combined : 'unknown python process error',
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
            ];
        }

        return [
            'ok' => true,
            'message' => $stdout !== '' ? $stdout : 'ok',
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $process->getExitCode(),
        ];
    }

    private function errorResult(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'stdout' => '', 'stderr' => '', 'exit_code' => null];
    }

    public function ensureHealthyOrThrow(int $timeoutSeconds = 20): void
    {
        $result = $this->check($timeoutSeconds);
        if (! $result['ok']) {
            throw new RuntimeException($result['message']);
        }
    }
}

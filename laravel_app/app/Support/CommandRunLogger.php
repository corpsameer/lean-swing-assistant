<?php

namespace App\Support;

use App\Models\Run;
use Throwable;

class CommandRunLogger
{
    /** @param array<string, mixed> $meta */
    public function start(string $runType, array $meta = []): int
    {
        $run = Run::create([
            'run_type' => $runType,
            'status' => 'running',
            'started_at' => now('UTC'),
            'meta_json' => $this->normalizeMeta($meta),
        ]);

        return (int) $run->id;
    }

    /** @param array<string, mixed> $meta */
    public function step(int $runId, string $step, string $status = 'started', array $meta = []): void
    {
        $run = Run::query()->find($runId);
        if (! $run) {
            return;
        }

        $payload = $this->currentMeta($run);
        $steps = $payload['steps'] ?? [];
        if (! is_array($steps)) {
            $steps = [];
        }

        $now = now('UTC')->toIso8601String();
        $entry = array_merge([
            'step' => $step,
            'status' => $status,
        ], $meta);

        if (! array_key_exists('started_at', $entry)) {
            $entry['started_at'] = $now;
        }
        if (in_array($status, ['completed', 'failed', 'skipped'], true) && ! array_key_exists('completed_at', $entry)) {
            $entry['completed_at'] = $now;
        }

        $steps[] = $entry;
        $payload['steps'] = $steps;
        $run->meta_json = $payload;
        $run->save();
    }

    /** @param array<string, mixed> $meta */
    public function complete(int $runId, array $meta = []): void
    {
        $this->finish($runId, 'completed', $meta);
    }

    /** @param array<string, mixed> $meta */
    public function fail(int $runId, string $message, array $meta = []): void
    {
        $this->finish($runId, 'failed', array_merge([
            'error_message' => $message,
        ], $meta));
    }

    /** @param array<string, mixed> $meta */
    public function skip(int $runId, string $message, array $meta = []): void
    {
        $this->finish($runId, 'skipped', array_merge([
            'message' => $message,
            'skip_reason' => $message,
        ], $meta));
    }

    /** @param array<string, mixed> $meta */
    private function finish(int $runId, string $status, array $meta = []): void
    {
        $run = Run::query()->find($runId);
        if (! $run) {
            return;
        }

        $payload = array_replace_recursive($this->currentMeta($run), $this->normalizeMeta($meta));
        $run->status = $status;
        $run->completed_at = now('UTC');
        $run->meta_json = $payload;
        $run->save();
    }

    /** @return array<string, mixed> */
    private function currentMeta(Run $run): array
    {
        $meta = $run->meta_json;

        return is_array($meta) ? $meta : [];
    }

    /** @param array<string, mixed> $meta @return array<string, mixed> */
    private function normalizeMeta(array $meta): array
    {
        foreach ($meta as $key => $value) {
            if ($value instanceof Throwable) {
                $meta[$key] = [
                    'exception_class' => $value::class,
                    'error_message' => $value->getMessage(),
                ];
            }
        }

        return $meta;
    }
}

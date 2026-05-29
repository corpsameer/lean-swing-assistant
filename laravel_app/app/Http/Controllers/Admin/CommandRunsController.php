<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AdminTableControls;
use App\Http\Controllers\Controller;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CommandRunsController extends Controller
{
    use AdminTableControls;

    /** @var array<int, string> */
    private array $errorKeys = [
        'error',
        'error_message',
        'message',
        'warning',
        'warnings',
        'failed_reason',
    ];

    /** @var array<string, array<int, string>> */
    private array $summaryKeysByRunType = [
        'weekend_scan' => ['total_scanned', 'passed', 'rejected'],
        'compute_daily_metrics' => ['symbols_scanned', 'metrics_computed', 'skipped_count', 'error_count'],
        'prompt_weekend_rank' => ['candidates', 'ranked', 'kept', 'rejected', 'errors'],
        'weekend_prompt_rank' => ['candidates_sent', 'candidates_ranked', 'candidates_updated', 'error_count', 'errors'],
        'workflow_weekend_scan' => ['message', 'error_message', 'failed_step', 'valid_symbols', 'scan_passed'],
        'workflow_daily_refine' => ['message', 'error_message', 'failed_step', 'valid_symbols', 'metrics_computed'],
        'ibkr_health_check' => ['message', 'error_message'],
        'build_ibkr_universe' => ['message', 'error_message', 'raw_symbols_returned', 'unique_symbols', 'inserted', 'updated', 'errors'],
        'intraday_validate' => ['active_candidates_scanned', 'candidates_sent_to_model', 'enter_now_count', 'wait_count', 'reject_count', 'trade_setups_created', 'skipped_score_below_threshold', 'skipped_missing_score', 'errors'],
        'simulate_status' => ['orders_checked', 'entered', 'closed', 'tp_hit', 'sl_hit', 'errors'],
        'trade_review' => ['closed_trades_found', 'trades_reviewed', 'skipped_already_reviewed', 'errors'],
    ];

    public function index(Request $request)
    {
        $table = 'runs';
        $columns = Schema::getColumnListing($table);
        $has = fn (string $column): bool => in_array($column, $columns, true);

        $pageSizes = $this->pageSizes();
        $pageSize = $this->getPageSize($request);

        $runType = trim((string) $request->query('run_type', ''));
        $status = trim((string) $request->query('status', ''));
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $search = trim((string) $request->query('search', ''));
        $hasError = (string) $request->query('has_error', 'all');
        if (! in_array($hasError, ['all', 'yes', 'no'], true)) {
            $hasError = 'all';
        }

        $query = DB::table($table);

        if ($runType !== '' && $has('run_type')) {
            $query->where('run_type', $runType);
        }

        if ($status !== '' && $has('status')) {
            $query->where('status', $status);
        }

        if ($has('started_at')) {
            $this->applyDateRange($query, 'started_at', $from, $to);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search, $has) {
                if ($has('run_type')) {
                    $q->orWhere('run_type', 'like', '%'.$search.'%');
                }
                if ($has('status')) {
                    $q->orWhere('status', 'like', '%'.$search.'%');
                }
                if ($has('meta_json')) {
                    $q->orWhere('meta_json', 'like', '%'.$search.'%');
                }
            });
        }

        if ($hasError !== 'all' && $has('meta_json')) {
            $query->where(function ($q) use ($hasError) {
                foreach ($this->errorKeys as $key) {
                    if ($hasError === 'yes') {
                        $q->orWhere('meta_json', 'like', '%"'.$key.'"%');
                    } else {
                        $q->where('meta_json', 'not like', '%"'.$key.'"%');
                    }
                }
            });
        }

        $allowedSorts = ['id' => 'id'];
        foreach (['run_type', 'status', 'started_at', 'completed_at'] as $column) {
            if ($has($column)) {
                $allowedSorts[$column] = $column;
            }
        }

        $defaultSort = $has('started_at') ? 'started_at' : 'id';
        [$currentSort, $currentDirection, $sortColumn] = $this->getSort($request, $allowedSorts, $defaultSort, 'desc');

        $query->orderBy($sortColumn, $currentDirection);
        if ($sortColumn !== 'id' && $has('id')) {
            $query->orderBy('id', 'desc');
        }

        $runs = $query->paginate($pageSize)->appends($request->query());
        $runs->getCollection()->transform(fn ($run) => $this->decorateRun($run));

        return view('admin.command-runs.index', [
            'runs' => $runs,
            'columns' => $columns,
            'pageSizes' => $pageSizes,
            'filters' => compact('runType', 'status', 'from', 'to', 'search', 'hasError', 'pageSize'),
            'currentSort' => $currentSort,
            'currentDirection' => $currentDirection,
            'runTypes' => $has('run_type') ? DB::table($table)->whereNotNull('run_type')->distinct()->orderBy('run_type')->pluck('run_type') : collect(),
            'statuses' => $has('status') ? DB::table($table)->whereNotNull('status')->distinct()->orderBy('status')->pluck('status') : collect(),
        ]);
    }

    public function show(int $id)
    {
        $table = 'runs';
        $columns = Schema::getColumnListing($table);
        $run = DB::table($table)->where('id', $id)->first();

        abort_if($run === null, 404);

        return view('admin.command-runs.show', [
            'run' => $this->decorateRun($run),
            'columns' => $columns,
        ]);
    }

    private function decorateRun(object $run): object
    {
        [$meta, $metaValid] = $this->decodeMeta($run->meta_json ?? null);

        $run->decoded_meta = $meta;
        $run->meta_valid = $metaValid;
        $run->summary = $this->metaSummary((string) ($run->run_type ?? ''), $meta, $metaValid);
        $run->error_message = $this->errorMessage($meta, $metaValid);
        $run->duration = $this->duration($run->started_at ?? null, $run->completed_at ?? null);
        $run->status_badge_class = $this->statusBadgeClass((string) ($run->status ?? ''));
        $run->pretty_meta_json = $this->prettyMetaJson($run->meta_json ?? null, $meta, $metaValid);

        return $run;
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    private function decodeMeta(mixed $rawMeta): array
    {
        if ($rawMeta === null || $rawMeta === '') {
            return [[], false];
        }

        if (is_array($rawMeta)) {
            return [$rawMeta, true];
        }

        if (! is_string($rawMeta)) {
            return [[], false];
        }

        $decoded = json_decode($rawMeta, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? [$decoded, true] : [[], false];
    }

    /** @param array<string, mixed> $meta */
    private function metaSummary(string $runType, array $meta, bool $metaValid): string
    {
        if (! $metaValid || $meta === []) {
            return 'Invalid/empty meta';
        }

        $keys = $this->summaryKeysByRunType[$runType] ?? [];
        $parts = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $meta)) {
                $parts[] = $key.': '.$this->compactValue($meta[$key]);
            }
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        return Str::limit(json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Invalid/empty meta', 160);
    }

    /** @param array<string, mixed> $meta */
    private function errorMessage(array $meta, bool $metaValid): string
    {
        if (! $metaValid) {
            return '—';
        }

        foreach ($this->errorKeys as $key) {
            if (array_key_exists($key, $meta) && $meta[$key] !== null && $meta[$key] !== '' && $meta[$key] !== []) {
                return $key.': '.$this->compactValue($meta[$key]);
            }
        }

        return '—';
    }

    private function compactValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) ($value ?? 'null');
        }

        return Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—', 120);
    }

    private function duration(mixed $startedAt, mixed $completedAt): string
    {
        if ($startedAt === null || $startedAt === '') {
            return '—';
        }

        if ($completedAt === null || $completedAt === '') {
            return 'Running';
        }

        $started = strtotime($this->dateToString($startedAt));
        $completed = strtotime($this->dateToString($completedAt));

        if ($started === false || $completed === false) {
            return '—';
        }

        $seconds = max(0, $completed - $started);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $minutes.'m '.$remainingSeconds.'s';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours.'h '.$remainingMinutes.'m';
    }

    private function dateToString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function statusBadgeClass(string $status): string
    {
        return match (strtolower($status)) {
            'completed', 'success', 'succeeded' => 'badge green',
            'failed', 'error', 'completed_with_errors' => 'badge red',
            'running', 'started', 'in_progress' => 'badge blue',
            'skipped' => 'badge yellow',
            default => 'badge gray',
        };
    }

    /** @param array<string, mixed> $meta */
    private function prettyMetaJson(mixed $rawMeta, array $meta, bool $metaValid): string
    {
        if (! $metaValid) {
            return $rawMeta === null || $rawMeta === '' ? 'Invalid/empty meta' : (string) $rawMeta;
        }

        return json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Invalid/empty meta';
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AdminTableControls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WatchlistController extends Controller
{
    use AdminTableControls;
    public function index(Request $request)
    {
        $table = 'watchlist_candidates';
        $columns = Schema::getColumnListing($table);
        $has = fn (string $column): bool => in_array($column, $columns, true);

        $pageSizes = $this->pageSizes();
        $pageSize = $this->getPageSize($request);

        $symbol = trim((string) $request->query('symbol', ''));
        $status = trim((string) $request->query('status', ''));
        $setupType = trim((string) $request->query('setup_type', ''));
        $stage = trim((string) $request->query('stage', ''));
        $minScore = $request->query('min_score');
        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');

        $query = DB::table($table)
            ->leftJoin('symbols', 'symbols.id', '=', 'watchlist_candidates.symbol_id')
            ->select('watchlist_candidates.*', 'symbols.symbol as symbol_text');

        if ($symbol !== '') {
            $query->where(function ($sub) use ($symbol) {
                $sub->where('symbols.symbol', 'like', '%'.$symbol.'%')
                    ->orWhere('watchlist_candidates.symbol_id', 'like', '%'.$symbol.'%');
            });
        }

        if ($status !== '' && $has('status')) {
            $query->where('watchlist_candidates.status', $status);
        }
        if ($setupType !== '' && $has('setup_type')) {
            $query->where('watchlist_candidates.setup_type', $setupType);
        }
        if ($stage !== '' && $has('stage')) {
            $query->where('watchlist_candidates.stage', $stage);
        }
        if ($minScore !== null && $minScore !== '' && $has('score_total')) {
            $query->where('watchlist_candidates.score_total', '>=', (float) $minScore);
        }
        if ($dateFrom !== '' && $has('created_at')) {
            $query->whereDate('watchlist_candidates.created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '' && $has('created_at')) {
            $query->whereDate('watchlist_candidates.created_at', '<=', $dateTo);
        }

        $allowedSorts = [
            'id' => 'watchlist_candidates.id',
            'symbol' => 'symbols.symbol',
        ];
        foreach ([
            'status' => $has('status') ? 'watchlist_candidates.status' : null,
            'candidate_stage' => $has('stage') ? 'watchlist_candidates.stage' : null,
            'score_total' => $has('score_total') ? 'watchlist_candidates.score_total' : null,
            'confidence' => $has('confidence') ? 'watchlist_candidates.confidence' : null,
            'setup_type' => $has('setup_type') ? 'watchlist_candidates.setup_type' : null,
            'created_at' => $has('created_at') ? 'watchlist_candidates.created_at' : null,
            'updated_at' => $has('updated_at') ? 'watchlist_candidates.updated_at' : null,
        ] as $key => $column) {
            if ($column !== null) {
                $allowedSorts[$key] = $column;
            }
        }
        $defaultSort = $has('score_total') ? 'score_total' : 'id';
        [$currentSort, $currentDirection, $sortColumn] = $this->getSort($request, $allowedSorts, $defaultSort, 'desc');

        $query->orderBy($sortColumn, $currentDirection)->orderByDesc('watchlist_candidates.id');

        $rows = $query->paginate($pageSize)->appends($request->query());

        $base = DB::table($table);
        $stats = [
            'total' => (clone $base)->count(),
            'active' => $has('status') ? (clone $base)->whereIn('status', ['active', 'enter_now', 'current'])->count() : null,
            'enter_now' => $has('status') ? (clone $base)->where('status', 'enter_now')->count() : null,
            'rejected_wait' => $has('status') ? (clone $base)->whereIn('status', ['removed', 'rejected', 'wait', 'planned'])->count() : null,
            'avg_score' => $has('score_total') ? (float) ((clone $base)->avg('score_total') ?? 0) : null,
        ];

        return view('admin.watchlist.index', [
            'rows' => $rows,
            'columns' => $columns,
            'pageSizes' => $pageSizes,
            'stats' => $stats,
            'filters' => compact('symbol', 'status', 'setupType', 'stage', 'minScore', 'dateFrom', 'dateTo', 'pageSize'),
            'currentSort' => $currentSort,
            'currentDirection' => $currentDirection,
            'statuses' => $has('status') ? DB::table($table)->whereNotNull('status')->distinct()->orderBy('status')->pluck('status') : collect(),
            'setupTypes' => $has('setup_type') ? DB::table($table)->whereNotNull('setup_type')->distinct()->orderBy('setup_type')->pluck('setup_type') : collect(),
            'stages' => $has('stage') ? DB::table($table)->whereNotNull('stage')->distinct()->orderBy('stage')->pluck('stage') : collect(),
        ]);
    }
}

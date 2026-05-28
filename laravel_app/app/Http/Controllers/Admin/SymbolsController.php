<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AdminTableControls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SymbolsController extends Controller
{
    use AdminTableControls;
    public function index(Request $request)
    {
        $table = 'symbols';
        $columns = Schema::getColumnListing($table);
        $has = fn (string $column): bool => in_array($column, $columns, true);

        $pageSizes = $this->pageSizes();
        $pageSize = $this->getPageSize($request);

        $symbol = trim((string) $request->query('symbol', ''));
        $active = (string) $request->query('active', 'all');
        $source = trim((string) $request->query('source', ''));
        $exchange = trim((string) $request->query('exchange', ''));
        $lastSeenFrom = (string) $request->query('last_seen_from', '');

        $query = DB::table($table);

        if ($symbol !== '' && $has('symbol')) {
            $query->where('symbol', 'like', '%'.$symbol.'%');
        }

        if ($active === 'active' && $has('is_active')) {
            $query->where('is_active', true);
        }
        if ($active === 'inactive' && $has('is_active')) {
            $query->where('is_active', false);
        }

        if ($source !== '' && $has('source')) {
            $query->where('source', $source);
        }

        if ($exchange !== '' && $has('exchange')) {
            $query->where('exchange', $exchange);
        }

        if ($lastSeenFrom !== '' && $has('last_seen_at')) {
            $query->whereDate('last_seen_at', '>=', $lastSeenFrom);
        }

        $allowedSorts = ['id' => 'id'];
        foreach ([
            'symbol' => 'symbol',
            'name' => $has('company_name') ? 'company_name' : ($has('security_name') ? 'security_name' : null),
            'exchange' => $has('exchange') ? 'exchange' : null,
            'source' => $has('source') ? 'source' : null,
            'is_active' => $has('is_active') ? 'is_active' : null,
            'last_seen_at' => $has('last_seen_at') ? 'last_seen_at' : null,
            'created_at' => $has('created_at') ? 'created_at' : null,
            'updated_at' => $has('updated_at') ? 'updated_at' : null,
        ] as $key => $column) {
            if ($column !== null) {
                $allowedSorts[$key] = $column;
            }
        }
        $defaultSort = $has('symbol') ? 'symbol' : 'id';
        $defaultDirection = $has('symbol') ? 'asc' : 'desc';
        [$currentSort, $currentDirection, $sortColumn] = $this->getSort($request, $allowedSorts, $defaultSort, $defaultDirection);

        $query->orderBy($sortColumn, $currentDirection);
        if ($sortColumn !== 'id' && $has('id')) {
            $query->orderBy('id');
        }

        $rows = $query->paginate($pageSize)->appends($request->query());

        $base = DB::table($table);
        $stats = [
            'total' => (clone $base)->count(),
            'active' => $has('is_active') ? (clone $base)->where('is_active', true)->count() : null,
            'inactive' => $has('is_active') ? (clone $base)->where('is_active', false)->count() : null,
            'recent_seen' => $has('last_seen_at') ? (clone $base)->where('last_seen_at', '>=', now()->subDays(7))->count() : null,
            'source_counts' => $has('source') ? (clone $base)->select('source', DB::raw('count(*) as c'))->groupBy('source')->pluck('c', 'source')->toArray() : [],
        ];

        return view('admin.symbols.index', [
            'rows' => $rows,
            'columns' => $columns,
            'pageSizes' => $pageSizes,
            'stats' => $stats,
            'filters' => compact('symbol', 'active', 'source', 'exchange', 'lastSeenFrom', 'pageSize'),
            'currentSort' => $currentSort,
            'currentDirection' => $currentDirection,
            'sources' => $has('source') ? DB::table($table)->whereNotNull('source')->distinct()->orderBy('source')->pluck('source') : collect(),
            'exchanges' => $has('exchange') ? DB::table($table)->whereNotNull('exchange')->distinct()->orderBy('exchange')->pluck('exchange') : collect(),
        ]);
    }
}

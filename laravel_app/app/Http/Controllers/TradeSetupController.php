<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AdminTableControls;
use App\Models\TradeSetup;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TradeSetupController extends Controller
{
    use AdminTableControls;

    public function index(Request $request): View
    {
        $pageSize = $this->getPageSize($request);
        $filters = [
            'symbol' => trim((string) $request->query('symbol', '')),
            'status' => trim((string) $request->query('status', '')),
            'setupType' => trim((string) $request->query('setup_type', '')),
            'candidateStage' => trim((string) $request->query('candidate_stage', '')),
            'dateFrom' => (string) $request->query('date_from', ''),
            'dateTo' => (string) $request->query('date_to', ''),
            'pageSize' => $pageSize,
        ];

        $allowedSorts = [
            'id' => 'trade_setups.id',
            'symbol' => 'symbols.symbol',
            'status' => 'trade_setups.status',
            'entry_price' => 'trade_setups.entry_price',
            'stop_price' => 'trade_setups.stop_price',
            'target1_price' => 'trade_setups.target1_price',
            'target2_price' => 'trade_setups.target2_price',
            'setup_type' => 'watchlist_candidates.setup_type',
            'candidate_stage' => 'watchlist_candidates.stage',
            'created_at' => 'trade_setups.created_at',
            'updated_at' => 'trade_setups.updated_at',
        ];
        [$currentSort, $currentDirection, $sortColumn] = $this->getSort($request, $allowedSorts, 'id', 'desc');

        $query = TradeSetup::query()
            ->with(['symbol:id,symbol', 'sourceCandidate:id,setup_type,stage'])
            ->leftJoin('symbols', 'symbols.id', '=', 'trade_setups.symbol_id')
            ->leftJoin('watchlist_candidates', 'watchlist_candidates.id', '=', 'trade_setups.source_candidate_id')
            ->select('trade_setups.*');

        if ($filters['symbol'] !== '') {
            $query->where('symbols.symbol', 'like', '%'.$filters['symbol'].'%');
        }
        if ($filters['status'] !== '') {
            $query->where('trade_setups.status', $filters['status']);
        }
        if ($filters['setupType'] !== '') {
            $query->where('watchlist_candidates.setup_type', $filters['setupType']);
        }
        if ($filters['candidateStage'] !== '') {
            $query->where('watchlist_candidates.stage', $filters['candidateStage']);
        }
        $this->applyDateRange($query, 'trade_setups.created_at', $filters['dateFrom'], $filters['dateTo']);

        $tradeSetups = $query
            ->orderBy($sortColumn, $currentDirection)
            ->orderByDesc('trade_setups.id')
            ->paginate($pageSize)
            ->appends($request->query());

        return view('trade_setups.index', [
            'tradeSetups' => $tradeSetups,
            'pageSizes' => $this->pageSizes(),
            'filters' => $filters,
            'currentSort' => $currentSort,
            'currentDirection' => $currentDirection,
            'statuses' => TradeSetup::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status'),
            'setupTypes' => TradeSetup::query()->join('watchlist_candidates', 'watchlist_candidates.id', '=', 'trade_setups.source_candidate_id')->whereNotNull('watchlist_candidates.setup_type')->distinct()->orderBy('watchlist_candidates.setup_type')->pluck('watchlist_candidates.setup_type'),
            'candidateStages' => TradeSetup::query()->join('watchlist_candidates', 'watchlist_candidates.id', '=', 'trade_setups.source_candidate_id')->whereNotNull('watchlist_candidates.stage')->distinct()->orderBy('watchlist_candidates.stage')->pluck('watchlist_candidates.stage'),
        ]);
    }
}

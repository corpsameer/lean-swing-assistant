<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AdminTableControls;
use App\Models\TradeSetup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TradeController extends Controller
{
    use AdminTableControls;

    public function index(Request $request): View
    {
        $pageSize = $this->getPageSize($request);
        $filters = [
            'symbol' => trim((string) $request->query('symbol', '')),
            'tradeStatus' => trim((string) $request->query('trade_status', '')),
            'orderStatus' => trim((string) $request->query('order_status', '')),
            'setupType' => trim((string) $request->query('setup_type', '')),
            'exitReason' => trim((string) $request->query('exit_reason', '')),
            'outcome' => (string) $request->query('outcome', 'all'),
            'reviewed' => (string) $request->query('reviewed', 'all'),
            'dateFrom' => (string) $request->query('date_from', ''),
            'dateTo' => (string) $request->query('date_to', ''),
            'pageSize' => $pageSize,
        ];

        $allowedSorts = [
            'trade_setup_id' => 'trade_setups.id',
            'symbol' => 'symbols.symbol',
            'trade_status' => 'trade_setups.status',
            'order_status' => 'latest_orders.status',
            'setup_type' => 'watchlist_candidates.setup_type',
            'entry_price' => 'trade_setups.entry_price',
            'stop_price' => 'trade_setups.stop_price',
            'target1_price' => 'trade_setups.target1_price',
            'placed_at' => 'latest_orders.placed_at',
            'filled_at' => 'latest_orders.filled_at',
        ];
        [$currentSort, $currentDirection, $sortColumn] = $this->getSort($request, $allowedSorts, 'trade_setup_id', 'desc');

        $latestOrderIds = DB::table('orders')->selectRaw('MAX(id) as id, trade_setup_id')->groupBy('trade_setup_id');

        $query = TradeSetup::query()
            ->with([
                'symbol:id,symbol',
                'orders' => fn ($query) => $query->orderByDesc('id'),
                'sourceCandidate:id,setup_type',
                'tradeReview:id,trade_setup_id,review_text,lessons_json',
            ])
            ->leftJoin('symbols', 'symbols.id', '=', 'trade_setups.symbol_id')
            ->leftJoin('watchlist_candidates', 'watchlist_candidates.id', '=', 'trade_setups.source_candidate_id')
            ->leftJoinSub($latestOrderIds, 'latest_order_ids', function ($join) {
                $join->on('latest_order_ids.trade_setup_id', '=', 'trade_setups.id');
            })
            ->leftJoin('orders as latest_orders', 'latest_orders.id', '=', 'latest_order_ids.id')
            ->leftJoin('trade_reviews', 'trade_reviews.trade_setup_id', '=', 'trade_setups.id')
            ->select('trade_setups.*');

        if ($filters['symbol'] !== '') {
            $query->where('symbols.symbol', 'like', '%'.$filters['symbol'].'%');
        }
        if ($filters['tradeStatus'] !== '') {
            $query->where('trade_setups.status', $filters['tradeStatus']);
        }
        if ($filters['orderStatus'] !== '') {
            $query->where('latest_orders.status', $filters['orderStatus']);
        }
        if ($filters['setupType'] !== '') {
            $query->where(function ($sub) use ($filters) {
                $sub->where('watchlist_candidates.setup_type', $filters['setupType'])
                    ->orWhere('latest_orders.meta_json->setup_type', $filters['setupType']);
            });
        }
        if ($filters['exitReason'] !== '') {
            $query->where('latest_orders.meta_json->exit_reason', $filters['exitReason']);
        }
        if ($filters['outcome'] === 'win') {
            $query->where('latest_orders.meta_json->pnl_percent', '>', 0);
        } elseif ($filters['outcome'] === 'loss') {
            $query->where('latest_orders.meta_json->pnl_percent', '<', 0);
        } elseif ($filters['outcome'] === 'open') {
            $query->where(function ($sub) {
                $sub->whereNull('latest_orders.meta_json->pnl_percent')
                    ->orWhereNull('latest_orders.meta_json->simulated_closed_at');
            });
        }
        if ($filters['reviewed'] === 'reviewed') {
            $query->whereNotNull('trade_reviews.id');
        } elseif ($filters['reviewed'] === 'not_reviewed') {
            $query->whereNull('trade_reviews.id');
        }
        $this->applyDateRange($query, 'latest_orders.placed_at', $filters['dateFrom'], $filters['dateTo']);

        $tradeSetups = $query
            ->orderBy($sortColumn, $currentDirection)
            ->orderByDesc('trade_setups.id')
            ->paginate($pageSize)
            ->appends($request->query());

        $tradeSetups->getCollection()->transform(function (TradeSetup $tradeSetup) {
            $tradeSetup->setRelation('latestOrder', $tradeSetup->orders->first());

            return $tradeSetup;
        });

        return view('trades.index', [
            'tradeSetups' => $tradeSetups,
            'pageSizes' => $this->pageSizes(),
            'filters' => $filters,
            'currentSort' => $currentSort,
            'currentDirection' => $currentDirection,
            'tradeStatuses' => TradeSetup::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status'),
            'orderStatuses' => DB::table('orders')->whereNotNull('status')->distinct()->orderBy('status')->pluck('status'),
            'setupTypes' => DB::table('watchlist_candidates')->whereNotNull('setup_type')->distinct()->orderBy('setup_type')->pluck('setup_type'),
        ]);
    }
}

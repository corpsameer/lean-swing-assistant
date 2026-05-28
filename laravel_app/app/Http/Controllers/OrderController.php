<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AdminTableControls;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    use AdminTableControls;

    public function index(Request $request): View
    {
        $pageSize = $this->getPageSize($request);
        $filters = [
            'symbol' => trim((string) $request->query('symbol', '')),
            'status' => trim((string) $request->query('status', '')),
            'executionDriver' => trim((string) $request->query('execution_driver', '')),
            'side' => trim((string) $request->query('side', '')),
            'orderType' => trim((string) $request->query('order_type', '')),
            'dateFrom' => (string) $request->query('date_from', ''),
            'dateTo' => (string) $request->query('date_to', ''),
            'hasPnl' => (string) $request->query('has_pnl', 'all'),
            'exitReason' => trim((string) $request->query('exit_reason', '')),
            'pageSize' => $pageSize,
        ];

        $allowedSorts = [
            'id' => 'orders.id',
            'symbol' => 'symbols.symbol',
            'trade_setup_id' => 'orders.trade_setup_id',
            'status' => 'orders.status',
            'side' => 'orders.side',
            'order_type' => 'orders.order_type',
            'quantity' => 'orders.quantity',
            'limit_price' => 'orders.limit_price',
            'stop_price' => 'orders.stop_price',
            'placed_at' => 'orders.placed_at',
            'filled_at' => 'orders.filled_at',
        ];
        [$currentSort, $currentDirection, $sortColumn] = $this->getSort($request, $allowedSorts, 'placed_at', 'desc');

        $query = Order::query()
            ->with(['symbol:id,symbol'])
            ->leftJoin('symbols', 'symbols.id', '=', 'orders.symbol_id')
            ->select('orders.*');

        if ($filters['symbol'] !== '') {
            $query->where('symbols.symbol', 'like', '%'.$filters['symbol'].'%');
        }
        if ($filters['status'] !== '') {
            $query->where('orders.status', $filters['status']);
        }
        if ($filters['side'] !== '') {
            $query->where('orders.side', $filters['side']);
        }
        if ($filters['orderType'] !== '') {
            $query->where('orders.order_type', $filters['orderType']);
        }
        if ($filters['executionDriver'] !== '') {
            $query->where(function ($sub) use ($filters) {
                $sub->where('orders.meta_json->execution_driver', $filters['executionDriver']);
                if ($filters['executionDriver'] === 'ibkr') {
                    $sub->orWhereNotNull('orders.broker_order_id');
                }
            });
        }
        if ($filters['hasPnl'] === 'yes') {
            $query->whereNotNull('orders.meta_json->pnl_percent');
        } elseif ($filters['hasPnl'] === 'no') {
            $query->whereNull('orders.meta_json->pnl_percent');
        }
        if ($filters['exitReason'] !== '') {
            $query->where('orders.meta_json->exit_reason', $filters['exitReason']);
        }
        $this->applyDateRange($query, 'orders.placed_at', $filters['dateFrom'], $filters['dateTo']);

        $orders = $query
            ->orderBy($sortColumn, $currentDirection)
            ->orderByDesc('orders.id')
            ->paginate($pageSize)
            ->appends($request->query());

        return view('orders.index', [
            'orders' => $orders,
            'pageSizes' => $this->pageSizes(),
            'filters' => $filters,
            'currentSort' => $currentSort,
            'currentDirection' => $currentDirection,
            'statuses' => Order::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status'),
            'sides' => Order::query()->whereNotNull('side')->distinct()->orderBy('side')->pluck('side'),
            'orderTypes' => Order::query()->whereNotNull('order_type')->distinct()->orderBy('order_type')->pluck('order_type'),
            'executionDrivers' => collect(['simulator', 'ibkr']),
        ]);
    }
}

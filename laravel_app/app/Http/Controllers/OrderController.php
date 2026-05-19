<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(): View
    {
        $orders = Order::query()
            ->with(['symbol:id,symbol'])
            ->orderByDesc('id')
            ->get();

        return view('orders.index', [
            'orders' => $orders,
        ]);
    }
}

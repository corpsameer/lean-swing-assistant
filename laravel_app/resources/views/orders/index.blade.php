<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f3f4f6; color: #111827; }
        main { max-width: 1400px; margin: 0 auto; padding: 24px; }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p { margin: 0; color: #4b5563; }
        nav { margin-top: 12px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        nav a { color: #2563eb; text-decoration: none; }
        .auth-actions { margin-left: auto; }
        .logout-btn { background: #111827; color: #fff; border: 0; border-radius: 6px; padding: 6px 10px; cursor: pointer; }
        .panel { margin-top: 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
        .empty { padding: 16px; color: #374151; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; border-bottom: 1px solid #e5e7eb; padding: 8px 10px; vertical-align: top; }
        th { background: #f9fafb; font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .text-green { color: #166534; font-weight: 600; }
        .text-red { color: #991b1b; font-weight: 600; }
    </style>
</head>
<body>
    <main>
        <h1>Orders</h1>
        <p>Read-only visibility of all order records.</p>
        <nav>
            <a href="{{ url('/admin/trade-setups') }}">Trade Setups</a>
            <a href="{{ url('/admin/orders') }}">Orders</a>
            <a href="{{ url('/admin/trades') }}">Trades</a>
            <a href="{{ url('/admin/analytics') }}">Analytics</a>
            <a href="{{ url('/admin/symbols') }}">Symbols</a>
            <a href="{{ url('/admin/watchlist') }}">Watchlist</a>
            <form class="auth-actions" method="POST" action="{{ url('/logout') }}">
                @csrf
                <button class="logout-btn" type="submit">Logout</button>
            </form>
        </nav>

        @if ($orders->isEmpty())
            <div class="panel empty">No orders found yet.</div>
        @else
            <div class="panel table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Symbol</th>
                            <th>Trade Setup ID</th>
                            <th>Execution Driver</th>
                            <th>Order Status</th>
                            <th>Side</th>
                            <th>Order Type</th>
                            <th>Quantity</th>
                            <th>Limit Price</th>
                            <th>Stop Price</th>
                            <th>Broker Order ID</th>
                            <th>Placed At</th>
                            <th>Filled At</th>
                            <th>Exit Reason</th>
                            <th>PnL %</th>
                            <th>R Multiple</th>
                            <th>Key Notes / Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            @php
                                $meta = $order->meta_json ?? [];
                                $executionDriver = $meta['execution_driver'] ?? null;
                                if (! $executionDriver && $order->broker_order_id) {
                                    $executionDriver = 'ibkr';
                                }

                                $keyReason = $meta['exit_reason']
                                    ?? $meta['normalized_reason']
                                    ?? $meta['execution_note']
                                    ?? $meta['note']
                                    ?? $meta['message']
                                    ?? '—';
                                $pnlPercent = $meta['pnl_percent'] ?? null;
                                $rMultiple = $meta['r_multiple'] ?? null;
                                $exitReason = $meta['exit_reason'] ?? '—';
                            @endphp
                            <tr>
                                <td class="nowrap">{{ $order->id }}</td>
                                <td class="nowrap">{{ $order->symbol?->symbol ?? ($order->symbol_id ? 'ID '.$order->symbol_id : '—') }}</td>
                                <td class="nowrap">{{ $order->trade_setup_id ?? '—' }}</td>
                                <td class="nowrap">{{ $executionDriver ?? '—' }}</td>
                                <td class="nowrap">{{ $order->status ?? '—' }}</td>
                                <td class="nowrap">{{ $order->side ?? '—' }}</td>
                                <td class="nowrap">{{ $order->order_type ?? '—' }}</td>
                                <td class="nowrap">{{ $order->quantity ?? '—' }}</td>
                                <td class="nowrap">{{ $order->limit_price ?? '—' }}</td>
                                <td class="nowrap">{{ $order->stop_price ?? '—' }}</td>
                                <td class="nowrap">{{ $order->broker_order_id ?? '—' }}</td>
                                <td class="nowrap">{{ $order->placed_at?->toDateTimeString() ?? '—' }}</td>
                                <td class="nowrap">{{ $order->filled_at?->toDateTimeString() ?? '—' }}</td>
                                <td>{{ $exitReason }}</td>
                                <td class="nowrap {{ is_numeric($pnlPercent) ? ((float) $pnlPercent >= 0 ? 'text-green' : 'text-red') : '' }}">{{ is_numeric($pnlPercent) ? number_format((float) $pnlPercent, 2) : '—' }}</td>
                                <td class="nowrap {{ is_numeric($rMultiple) ? ((float) $rMultiple >= 0 ? 'text-green' : 'text-red') : '' }}">{{ is_numeric($rMultiple) ? number_format((float) $rMultiple, 2) : '—' }}</td>
                                <td>{{ $keyReason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>
</body>
</html>

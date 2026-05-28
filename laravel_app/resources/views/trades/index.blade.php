<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trades</title>
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
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge-blue { background: #dbeafe; color: #1e3a8a; }
        .badge-gray { background: #e5e7eb; color: #374151; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-orange { background: #ffedd5; color: #9a3412; }
        .badge-dark { background: #d1d5db; color: #111827; }
        .text-green { color: #166534; font-weight: 600; }
        .text-red { color: #991b1b; font-weight: 600; }
    </style>
</head>
<body>
    <main>
        <h1>Trades</h1>
        <p>Read-only lifecycle view by trade setup and latest related order.</p>
        <nav>
            <a href="{{ url('/admin/trade-setups') }}">Trade Setups</a>
            <a href="{{ url('/admin/orders') }}">Orders</a>
            <a href="{{ url('/admin/trades') }}">Trades</a>
            <a href="{{ url('/admin/analytics') }}">Analytics</a>
            <form class="auth-actions" method="POST" action="{{ url('/logout') }}">
                @csrf
                <button class="logout-btn" type="submit">Logout</button>
            </form>
        </nav>

        @php
            $badgeClassForStatus = function (?string $status): string {
                return match ($status) {
                    'simulated_pending', 'planned', 'dry_run_only' => 'badge badge-blue',
                    'simulated_entered', 'entered', 'simulated_tp_hit' => 'badge badge-green',
                    'simulated_sl_hit' => 'badge badge-red',
                    'cancelled' => 'badge badge-orange',
                    'broker_rejected', 'broker_rejected_cash' => 'badge badge-red',
                    'closed' => 'badge badge-dark',
                    default => 'badge badge-gray',
                };
            };
        @endphp

        @if ($tradeSetups->isEmpty())
            <div class="panel empty">No trades found yet.</div>
        @else
            <div class="panel table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Trade Setup ID</th>
                            <th>Symbol</th>
                            <th>Trade Status</th>
                            <th>Order Status</th>
                            <th>Setup Type</th>
                            <th>Entry Price</th>
                            <th>Stop Price</th>
                            <th>Target 1</th>
                            <th>Target 2</th>
                            <th>Simulated Entry Price</th>
                            <th>Simulated Exit Price</th>
                            <th>Exit Reason</th>
                            <th>PnL %</th>
                            <th>PnL Amount</th>
                            <th>R Multiple</th>
                            <th>Entered At</th>
                            <th>Closed At</th>
                            <th>Review Status</th>
                            <th>Review Summary</th>
                            <th>Setup Quality</th>
                            <th>Final Verdict</th>
                            <th>Placed At</th>
                            <th>Filled At</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tradeSetups as $setup)
                            @php
                                $latestOrder = $setup->latestOrder;
                                $meta = $latestOrder?->meta_json ?? [];
                                $setupType = $meta['setup_type'] ?? $setup->sourceCandidate?->setup_type ?? '—';
                                $simEntry = $meta['simulated_entry_price'] ?? '—';
                                $simExit = $meta['simulated_exit_price'] ?? '—';
                                $exitReason = $meta['exit_reason'] ?? $meta['normalized_reason'] ?? $meta['execution_note'] ?? '—';
                                $pnlPercent = $meta['pnl_percent'] ?? null;
                                $pnlAmount = $meta['pnl_amount'] ?? null;
                                $rMultiple = $meta['r_multiple'] ?? null;
                                $enteredAt = $meta['simulated_entered_at'] ?? '—';
                                $closedAt = $meta['simulated_closed_at'] ?? '—';
                                $review = $setup->tradeReview;
                                $reviewJson = $review?->lessons_json ?? [];
                                $reviewStatus = $review ? 'reviewed' : 'pending';
                            @endphp
                            <tr>
                                <td class="nowrap">{{ $setup->id }}</td>
                                <td class="nowrap">{{ $setup->symbol?->symbol ?? ($setup->symbol_id ? 'ID '.$setup->symbol_id : '—') }}</td>
                                <td class="nowrap"><span class="{{ $badgeClassForStatus($setup->status) }}">{{ $setup->status ?? '—' }}</span></td>
                                <td class="nowrap"><span class="{{ $badgeClassForStatus($latestOrder?->status) }}">{{ $latestOrder?->status ?? '—' }}</span></td>
                                <td class="nowrap">{{ $setupType }}</td>
                                <td class="nowrap">{{ $setup->entry_price ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->stop_price ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->target1_price ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->target2_price ?? '—' }}</td>
                                <td class="nowrap">{{ $simEntry }}</td>
                                <td class="nowrap">{{ $simExit }}</td>
                                <td>{{ $exitReason }}</td>
                                <td class="nowrap {{ is_numeric($pnlPercent) ? ((float) $pnlPercent >= 0 ? 'text-green' : 'text-red') : '' }}">{{ is_numeric($pnlPercent) ? number_format((float) $pnlPercent, 2) : '—' }}</td>
                                <td class="nowrap {{ is_numeric($pnlAmount) ? ((float) $pnlAmount >= 0 ? 'text-green' : 'text-red') : '' }}">{{ is_numeric($pnlAmount) ? number_format((float) $pnlAmount, 2) : '—' }}</td>
                                <td class="nowrap {{ is_numeric($rMultiple) ? ((float) $rMultiple >= 0 ? 'text-green' : 'text-red') : '' }}">{{ is_numeric($rMultiple) ? number_format((float) $rMultiple, 2) : '—' }}</td>
                                <td class="nowrap">{{ $enteredAt }}</td>
                                <td class="nowrap">{{ $closedAt }}</td>
                                <td class="nowrap"><span class="{{ $review ? 'badge badge-green' : 'badge badge-gray' }}">{{ $reviewStatus }}</span></td>
                                <td>{{ $review?->review_text ?? '—' }}</td>
                                <td class="nowrap">{{ is_numeric($reviewJson['setup_quality_score'] ?? null) ? (int) $reviewJson['setup_quality_score'] : '—' }}</td>
                                <td class="nowrap">{{ $reviewJson['final_verdict'] ?? '—' }}</td>
                                <td class="nowrap">{{ $latestOrder?->placed_at?->toDateTimeString() ?? '—' }}</td>
                                <td class="nowrap">{{ $latestOrder?->filled_at?->toDateTimeString() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>
</body>
</html>

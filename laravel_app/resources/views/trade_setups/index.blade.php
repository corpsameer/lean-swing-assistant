<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade Setups</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f3f4f6; color: #111827; }
        main { max-width: 1200px; margin: 0 auto; padding: 24px; }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p { margin: 0; color: #4b5563; }
        .panel { margin-top: 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
        .empty { padding: 16px; color: #374151; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; border-bottom: 1px solid #e5e7eb; padding: 8px 10px; vertical-align: top; }
        th { background: #f9fafb; font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; white-space: nowrap; }
        td { background: #fff; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
    <main>
        <h1>Trade Setups</h1>
        <p>Read-only visibility of generated trade setups.</p>

        @if ($tradeSetups->isEmpty())
            <div class="panel empty">No trade setups found yet.</div>
        @else
            <div class="panel table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th>Status</th>
                            <th>Entry Price</th>
                            <th>Stop Price</th>
                            <th>Target 1</th>
                            <th>Target 2</th>
                            <th>Risk %</th>
                            <th>Setup Type</th>
                            <th>Candidate Stage</th>
                            <th>Notes</th>
                            <th>Created At</th>
                            <th>Updated At</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tradeSetups as $setup)
                            <tr>
                                <td class="nowrap">{{ $setup->symbol?->symbol ?? ($setup->symbol_id ? 'ID '.$setup->symbol_id : '—') }}</td>
                                <td class="nowrap">{{ $setup->status ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->entry_price ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->stop_price ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->target1_price ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->target2_price ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->risk_percent ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->sourceCandidate?->setup_type ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->sourceCandidate?->stage ?? '—' }}</td>
                                <td>{{ $setup->notes ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->created_at?->toDateTimeString() ?? '—' }}</td>
                                <td class="nowrap">{{ $setup->updated_at?->toDateTimeString() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>
</body>
</html>

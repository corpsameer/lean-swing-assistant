<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade Setups</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 text-gray-900">
    <main class="mx-auto max-w-7xl p-6">
        <h1 class="text-2xl font-semibold">Trade Setups</h1>
        <p class="mt-1 text-sm text-gray-600">Read-only visibility of generated trade setups.</p>

        @if ($tradeSetups->isEmpty())
            <div class="mt-6 rounded border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-700">
                No trade setups found yet.
            </div>
        @else
            <div class="mt-6 overflow-x-auto rounded bg-white shadow">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Symbol</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Entry Price</th>
                            <th class="px-3 py-2">Stop Price</th>
                            <th class="px-3 py-2">Target 1</th>
                            <th class="px-3 py-2">Target 2</th>
                            <th class="px-3 py-2">Risk %</th>
                            <th class="px-3 py-2">Setup Type</th>
                            <th class="px-3 py-2">Candidate Stage</th>
                            <th class="px-3 py-2">Notes</th>
                            <th class="px-3 py-2">Created At</th>
                            <th class="px-3 py-2">Updated At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @foreach ($tradeSetups as $setup)
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-3 py-2">
                                    {{ $setup->symbol?->symbol ?? ($setup->symbol_id ? 'ID '.$setup->symbol_id : '—') }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->status ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->entry_price ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->stop_price ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->target1_price ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->target2_price ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->risk_percent ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->sourceCandidate?->setup_type ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->sourceCandidate?->stage ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $setup->notes ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->created_at?->toDateTimeString() ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $setup->updated_at?->toDateTimeString() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>
</body>
</html>

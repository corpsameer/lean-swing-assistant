<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Command Run #{{ $run->id ?? '' }}</title>
<style>body{font-family:Arial;margin:0;background:#f3f4f6;color:#111827}main{max-width:1100px;margin:0 auto;padding:24px}nav{margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}nav a{color:#2563eb;text-decoration:none}.auth-actions{margin-left:auto}.logout-btn{background:#111827;color:#fff;border:0;border-radius:6px;padding:6px 10px}.panel{margin-top:16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px}.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600}.green{background:#dcfce7;color:#166534}.red{background:#fee2e2;color:#991b1b}.blue{background:#dbeafe;color:#1e40af}.yellow{background:#fef3c7;color:#92400e}.gray{background:#e5e7eb;color:#374151}.fields{display:grid;grid-template-columns:220px 1fr;border-top:1px solid #e5e7eb}.field-label,.field-value{padding:9px 10px;border-bottom:1px solid #e5e7eb}.field-label{font-size:12px;text-transform:uppercase;color:#6b7280;background:#f9fafb;font-weight:700}.field-value{overflow-wrap:anywhere}pre{white-space:pre-wrap;background:#111827;color:#f9fafb;border-radius:8px;padding:12px;overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:8px 10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#6b7280;background:#f9fafb}.back{color:#2563eb;text-decoration:none}</style></head><body><main>
<h1>Command Run #{{ $run->id ?? '—' }}</h1><p><a class="back" href="{{ url('/admin/command-runs') }}">← Back to Command Runs</a></p>
<nav><a href="{{ url('/admin/trade-setups') }}">Trade Setups</a><a href="{{ url('/admin/orders') }}">Orders</a><a href="{{ url('/admin/trades') }}">Trades</a><a href="{{ url('/admin/analytics') }}">Analytics</a><a href="{{ url('/admin/symbols') }}">Symbols</a><a href="{{ url('/admin/watchlist') }}">Watchlist</a><a href="{{ url('/admin/command-runs') }}">Command Runs</a><form class="auth-actions" method="POST" action="{{ url('/logout') }}">@csrf<button class="logout-btn" type="submit">Logout</button></form></nav>
<div class="panel"><div class="fields">
@foreach($columns as $column)
<div class="field-label">{{ $column }}</div><div class="field-value">@if($column === 'status')<span class="{{ $run->status_badge_class }}">{{ $run->status ?? 'unknown' }}</span>@elseif($column === 'meta_json')<span>{{ $run->summary }}</span>@else{{ $run->{$column} ?? '—' }}@endif</div>
@endforeach
<div class="field-label">Duration</div><div class="field-value">{{ $run->duration }}</div>
<div class="field-label">Error / Message</div><div class="field-value">{{ $run->error_message }}</div>
</div></div>
@if(!empty($steps))
<div class="panel"><h2>Steps</h2><table><thead><tr><th>Step</th><th>Status</th><th>Started</th><th>Completed</th><th>Message</th></tr></thead><tbody>
@foreach($steps as $step)
<tr><td>{{ $step['step'] ?? '—' }}</td><td>{{ $step['status'] ?? '—' }}</td><td>{{ $step['started_at'] ?? '—' }}</td><td>{{ $step['completed_at'] ?? '—' }}</td><td>{{ $step['message'] ?? '—' }}</td></tr>
@endforeach
</tbody></table></div>
@endif
<div class="panel"><h2>Meta JSON</h2><pre>{{ $run->pretty_meta_json }}</pre></div>
</main></body></html>

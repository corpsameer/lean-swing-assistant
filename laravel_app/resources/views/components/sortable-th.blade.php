@php
    $isCurrent = ($currentSort ?? request('sort')) === $sort;
    $activeDirection = strtolower($currentDirection ?? request('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
    $nextDirection = $isCurrent && $activeDirection === 'asc' ? 'desc' : 'asc';
    $query = array_merge(request()->query(), [
        'sort' => $sort,
        'direction' => $nextDirection,
    ]);
    unset($query['page']);
@endphp
<th>
    <a class="sort-link" href="{{ url()->current() }}?{{ http_build_query($query) }}">
        {{ $label }}@if($isCurrent) {{ $activeDirection === 'asc' ? '↑' : '↓' }}@endif
    </a>
</th>

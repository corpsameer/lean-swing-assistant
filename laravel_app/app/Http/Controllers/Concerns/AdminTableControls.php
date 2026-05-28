<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

trait AdminTableControls
{
    /** @return array<int, int> */
    protected function pageSizes(): array
    {
        return [10, 25, 50, 100, 200];
    }

    protected function getPageSize(Request $request): int
    {
        $pageSize = (int) $request->query('page_size', 25);

        return in_array($pageSize, $this->pageSizes(), true) ? $pageSize : 25;
    }

    /**
     * @param array<string, string> $allowedSorts
     * @return array{0: string, 1: string, 2: string}
     */
    protected function getSort(Request $request, array $allowedSorts, string $defaultSort, string $defaultDirection = 'asc'): array
    {
        $sort = (string) $request->query('sort', $defaultSort);
        if (! array_key_exists($sort, $allowedSorts)) {
            $sort = $defaultSort;
        }

        $direction = strtolower((string) $request->query('direction', $defaultDirection));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        return [$sort, $direction, $allowedSorts[$sort]];
    }

    protected function applyDateRange(EloquentBuilder|QueryBuilder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null && $from !== '') {
            $query->whereDate($column, '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->whereDate($column, '<=', $to);
        }
    }
}

<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Operations;

use Illuminate\Database\Eloquent\Builder;

final class SortApplier
{
    public function __construct(
        private readonly Sorter $sorter,
    ) {}

    /**
     * @param  array<int|string, string>  $sortableFields
     * @param  array<string, callable(Builder, string): void>  $customSorts
     */
    public function apply(
        Builder $query,
        ?string $sortField,
        int $sortOrder,
        array $sortableFields = [],
        array $customSorts = []
    ): void {
        if ($sortField === null || $sortOrder === 0) {
            return;
        }

        $direction = $this->normalizeDirection($sortOrder);
        $whitelist = $this->normalizeSortable($sortableFields);
        $legacyMode = $whitelist === [];

        if (! $legacyMode && ! array_key_exists($sortField, $whitelist)) {
            return;
        }

        if (isset($customSorts[$sortField]) && is_callable($customSorts[$sortField])) {
            $customSorts[$sortField]($query, $direction);

            return;
        }

        if ($legacyMode) {
            $this->sorter->apply($query, $sortField, $direction);

            return;
        }

        $this->sorter->apply($query, $whitelist[$sortField], $direction);
    }

    private function normalizeDirection(int $sortOrder): string
    {
        return $sortOrder === -1 ? 'desc' : 'asc';
    }

    /**
     * @param  array<int|string, string>  $sortable
     * @return array<string, string>
     */
    private function normalizeSortable(array $sortable): array
    {
        $normalized = [];

        foreach ($sortable as $key => $value) {
            if (is_int($key)) {
                $normalized[(string) $value] = (string) $value;

                continue;
            }

            $normalized[(string) $key] = (string) $value;
        }

        return $normalized;
    }
}

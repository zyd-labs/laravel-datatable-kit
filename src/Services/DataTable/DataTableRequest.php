<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable;

use Illuminate\Support\Arr;

final class DataTableRequest
{
    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function __construct(
        public readonly int $first,
        public readonly int $rows,
        public readonly ?string $sortField,
        public readonly int $sortOrder,
        public readonly ?string $globalSearch,
        public readonly ?array $filters
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            first: max(0, (int) ($payload['first'] ?? 0)),
            rows: max(1, min(1000, (int) ($payload['rows'] ?? 25))),
            sortField: Arr::get($payload, 'sortField'),
            sortOrder: (int) ($payload['sortOrder'] ?? 0),
            globalSearch: Arr::get($payload, 'global'),
            filters: Arr::get($payload, 'filters')
        );
    }
}


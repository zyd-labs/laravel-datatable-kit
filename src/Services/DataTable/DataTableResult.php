<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable;

use Illuminate\Support\Collection;

final class DataTableResult
{
    public function __construct(
        public readonly Collection $data,
        public readonly int $total,
        public readonly array $queryLogs = []
    ) {
    }

    public function toArray(): array
    {
        // Test
        return [
            'data' => $this->data,
            'total' => $this->total,
            'queries' => $this->queryLogs,
        ];
    }
}


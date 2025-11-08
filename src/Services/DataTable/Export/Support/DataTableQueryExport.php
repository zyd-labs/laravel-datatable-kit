<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Export\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

final class DataTableQueryExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    /**
     * @param  array<int, string>  $headings
     */
    public function __construct(
        private readonly Builder|QueryBuilder $query,
        private readonly array $headings,
        private readonly Closure $mapRow
    ) {
    }

    public function query(): Builder|QueryBuilder
    {
        return clone $this->query;
    }

    /**
     * @param  Model|\stdClass  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        $mapped = ($this->mapRow)($row);

        return array_values($mapped);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->headings;
    }
}


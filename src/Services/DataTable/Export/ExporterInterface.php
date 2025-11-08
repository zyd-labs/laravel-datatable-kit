<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Export;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

interface ExporterInterface
{
    /**
     * @param  array<string, string>  $columns
     * @param  Closure(mixed):array  $mapRow
     */
    public function export(
        Builder $query,
        array $columns,
        Closure $mapRow,
        string $fileName
    ): Response;
}


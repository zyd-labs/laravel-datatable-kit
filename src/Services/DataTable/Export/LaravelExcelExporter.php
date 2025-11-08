<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Export;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Export\Support\DataTableQueryExport;

final class LaravelExcelExporter implements ExporterInterface
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
    ): Response {
        $headings = array_values($columns);
        $keys = array_keys($columns);

        $export = new DataTableQueryExport(
            clone $query,
            $headings,
            static function ($row) use ($mapRow, $keys): array {
                $mapped = $mapRow($row);

                $values = [];

                foreach ($keys as $attribute) {
                    $values[] = $mapped[$attribute] ?? null;
                }

                return $values;
            }
        );

        $normalizedName = str_ends_with($fileName, '.xlsx') ? $fileName : "{$fileName}.xlsx";

        return Excel::download($export, $normalizedName);
    }
}


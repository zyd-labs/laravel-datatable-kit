<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Export;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvExporter implements ExporterInterface
{
    private const CHUNK_SIZE = 1000;

    /**
     * @param  array<string, string>  $columns
     * @param  Closure(Model|array):array  $mapRow
     */
    public function export(
        Builder $query,
        array $columns,
        Closure $mapRow,
        string $fileName
    ): StreamedResponse {
        $headers = array_values($columns);

        $response = new StreamedResponse(function () use ($query, $headers, $mapRow): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $headers, ';');

            $query->orderBy($query->getModel()->getQualifiedKeyName())
                ->chunk(self::CHUNK_SIZE, function ($chunkedRows) use ($handle, $mapRow): void {
                    foreach ($chunkedRows as $row) {
                        $mapped = $mapRow($row);
                        fputcsv($handle, array_map($this->stringify(...), $mapped), ';');
                    }
                });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $fileName)
        );

        return $response;
    }

    private function stringify(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}


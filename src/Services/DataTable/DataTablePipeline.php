<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use ZydLabs\LaravelDataTableKit\Contracts\DataTableExportable;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Export\ExporterInterface;

final class DataTablePipeline
{
    /**
     * @var callable|null
     */
    private $mapRow;

    /**
     * @param  Collection<int, mixed>  $data
     * @param  array<string, string>  $exportColumns
     * @param  callable|null  $mapRow
     */
    public function __construct(
        private readonly Collection $data,
        private readonly int $total,
        private readonly Builder $exportQuery,
        private readonly DataTableRequest $request,
        private readonly ?DataTableExportable $exporter,
        private readonly ExporterInterface $defaultExporter,
        private readonly array $exportColumns,
        ?callable $mapRow,
        private readonly ?string $fileName,
        private readonly array $queryLogs = [],
    ) {
        $this->mapRow = $mapRow;
    }

    public function toJson(): DataTableResult
    {
        return new DataTableResult($this->data, $this->total, $this->queryLogs);
    }

    public function toJsonResponse(): JsonResponse
    {
        return response()->json($this->toJson()->toArray());
    }

    public function toExport(): Response
    {
        if ($this->exporter !== null) {
            return $this->exporter->toResponse($this->request, clone $this->exportQuery);
        }

        $mapRow = $this->mapRow ?? fn ($row): array => $this->mapRowUsingColumns($row, $this->exportColumns);

        return $this->defaultExporter->export(
            clone $this->exportQuery,
            $this->exportColumns,
            $mapRow,
            $this->fileName ?? 'export.csv'
        );
    }

    /**
     * @param  array<string, string>  $columns
     */
    private function mapRowUsingColumns(mixed $row, array $columns): array
    {
        $payload = [];

        foreach ($columns as $attribute => $heading) {
            $payload[$heading] = data_get($row, $attribute);
        }

        return $payload;
    }
}


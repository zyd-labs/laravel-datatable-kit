<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use ZydLabs\LaravelDataTableKit\Contracts\DataTableExportable;
use ZydLabs\LaravelDataTableKit\Http\Requests\DataTableRequest as DataTableFormRequest;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Export\ExporterInterface;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\FilterApplier;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\GlobalSearchApplier;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Sorter;
use Illuminate\Support\Facades\DB;

final class DataTableManager
{
    public function __construct(
        private readonly Container $container,
        private readonly GlobalSearchApplier $globalSearchApplier,
        private readonly FilterApplier $filterApplier,
        private readonly Sorter $sorter,
        private readonly ExporterInterface $exporter
    ) {
    }

    /**
     * @param  array<int, string>  $searchableFields
     * @param  array<int, string>  $filterableFields
     * @param  Closure(Collection):Collection|null  $transform
     */
    public function handle(
        Builder $query,
        DataTableRequest|DataTableFormRequest|array $request,
        array $searchableFields = [],
        array $filterableFields = [],
        ?Closure $transform = null,
        array $customFilters = []
    ): DataTableResult {
        return $this->process(
            $query,
            $request,
            $searchableFields,
            $filterableFields,
            $transform,
            customFilters: $customFilters
        )->toJson();
    }

    /**
     * @param  array<int, string>  $searchableFields
     * @param  array<int, string>  $filterableFields
     * @param  array<string, string>  $exportColumns key => heading
     * @param  Closure(mixed):array|null  $mapRow
     */
    public function export(
        Builder $query,
        DataTableRequest|DataTableFormRequest|array $request,
        array $searchableFields,
        array $filterableFields,
        array $exportColumns,
        ?Closure $mapRow = null,
        DataTableExportable|string|null $customExporter = null,
        ?string $fileName = null,
        ?Closure $transform = null,
        array $customFilters = []
    ): Response {
        $pipeline = $this->process(
            $query,
            $request,
            $searchableFields,
            $filterableFields,
            $transform,
            $customExporter ?? null,
            $exportColumns,
            $mapRow,
            $fileName,
            $customFilters
        );

        return $pipeline->toExport();
    }

    public function process(
        Builder $query,
        DataTableRequest|DataTableFormRequest|array $request,
        array $searchableFields = [],
        array $filterableFields = [],
        ?Closure $transform = null,
        DataTableExportable|string|null $customExporter = null,
        array $exportColumns = [],
        ?Closure $mapRow = null,
        ?string $fileName = null,
        array $customFilters = []
    ): DataTablePipeline {
        $resolvedRequest = $this->normalizeRequest($request);

        $collectQueryLogs = $this->shouldCollectQueryLogs();
        if ($collectQueryLogs) {
            DB::flushQueryLog();
            DB::enableQueryLog();
        }

        $this->applyOperations($query, $resolvedRequest, $searchableFields, $filterableFields, $customFilters);

        $total = $this->count($query);

        $dataQuery = clone $query;
        $data = $dataQuery
            ->skip($resolvedRequest->first)
            ->take($resolvedRequest->rows)
            ->get();

        if ($transform !== null) {
            $data = $transform($data);
        }

        $queryLogs = [];
        if ($collectQueryLogs) {
            $queryLogs = DB::getQueryLog();
            DB::flushQueryLog();
            DB::disableQueryLog();
        }

        return new DataTablePipeline(
            data: $data,
            total: $total,
            exportQuery: clone $query,
            request: $resolvedRequest,
            exporter: $this->resolveExporter($customExporter),
            defaultExporter: $this->exporter,
            exportColumns: $exportColumns,
            mapRow: $mapRow,
            fileName: $fileName,
            queryLogs: $queryLogs
        );
    }

    /**
     * @param  array<int, string>  $searchableFields
     * @param  array<int, string>  $filterableFields
     */
    private function applyOperations(
        Builder $query,
        DataTableRequest $request,
        array $searchableFields,
        array $filterableFields,
        array $customFilters
    ): void {
        $this->globalSearchApplier->apply($query, $request->globalSearch, $searchableFields);

        if (! empty($request->filters)) {
            $this->filterApplier->apply($query, $request->filters, $filterableFields, $customFilters);
        }

        if ($request->sortField !== null && $request->sortOrder !== 0) {
            $this->sorter->apply($query, $request->sortField, $request->sortOrder);
        }
    }

    private function count(Builder $query): int
    {
        $totalQuery = clone $query;
        $baseTable = $totalQuery->getModel()->getTable();

        $joins = $totalQuery->getQuery()->joins ?? [];
        if (! empty($joins)) {
            $columns = $totalQuery->getQuery()->columns;
            if ($columns === null || $columns === []) {
                $totalQuery->select("{$baseTable}.id");
            }

            return $totalQuery->distinct()->count();
        }

        return $totalQuery->count();
    }

    /**
     * @param  array<string, string>  $exportColumns
     */
    private function mapRowUsingColumns(mixed $row, array $exportColumns): array
    {
        $payload = [];
        $arrayRow = $row instanceof Relation ? $row->getResults() : $row;

        foreach ($exportColumns as $attribute => $heading) {
            $payload[$attribute] = data_get($arrayRow, $attribute);
        }

        return $payload;
    }

    private function resolveExporter(
        DataTableExportable|string|null $exporter
    ): ?DataTableExportable {
        if ($exporter === null) {
            return null;
        }

        if (! $exporter instanceof DataTableExportable) {
            $exporter = $this->instantiateExporter($exporter);
        }

        return $exporter;
    }

    /**
     * @param  DataTableExportable|string  $exporter
     */
    private function instantiateExporter($exporter): DataTableExportable
    {
        if (is_string($exporter)) {
            $resolved = $this->container->make($exporter);
        } else {
            $resolved = $exporter;
        }

        if (! $resolved instanceof DataTableExportable) {
            throw new InvalidArgumentException('Custom exporter must implement DataTableExportable.');
        }

        return $resolved;
    }

    /**
     * @param  DataTableRequest|DataTableFormRequest|array<string, mixed>  $request
     */
    private function normalizeRequest(DataTableRequest|DataTableFormRequest|array $request): DataTableRequest
    {
        if ($request instanceof DataTableRequest) {
            return $request;
        }

        if ($request instanceof DataTableFormRequest) {
            return DataTableRequest::fromArray($request->validated());
        }

        return DataTableRequest::fromArray($request);
    }

    private function shouldCollectQueryLogs(): bool
    {
        return (bool) config('app.debug');
    }
}


<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\DataTable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use ZydLabs\LaravelDataTableKit\Http\Requests\DataTableRequest as DataTableFormRequest;
use ZydLabs\LaravelDataTableKit\Services\DataTable\DataTableManager;
use ZydLabs\LaravelDataTableKit\Services\DataTable\DataTablePipeline;

abstract class AbstractDataTable
{
    public function __construct(
        protected readonly DataTableManager $manager,
    ) {}

    public function render(DataTableFormRequest $request): JsonResponse
    {
        return $this->pipeline($request)->toJsonResponse();
    }

    public function export(DataTableFormRequest $request): Response
    {
        return $this->pipeline($request)->toExport();
    }

    protected function pipeline(DataTableFormRequest $request): DataTablePipeline
    {
        return $this->manager->process(
            $this->query($request),
            $request,
            $this->searchable(),
            $this->filterable(),
            fn (Collection $collection) => $this->afterLoad($collection),
            customExporter: $this->exporterClass(),
            customFilters: $this->customFilters(),
            sortableFields: $this->sortable(),
            customSorts: $this->customSorts()
        );
    }

    abstract protected function query(DataTableFormRequest $request): Builder;

    /**
     * @return array<int, string>
     */
    abstract protected function searchable(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function filterable(): array;

    protected function exporterClass(): ?string
    {
        return null;
    }

    /**
     * Whitelisted sort fields. Supports list entries and aliases:
     * ['created_at', 'company.name', 'display_name' => 'name']
     *
     * When empty, legacy mode applies: any sortField is passed to the sorter (backward compatible).
     * When non-empty, unknown sortField values are ignored (no SQL, no exception).
     *
     * @return array<int|string, string>
     */
    protected function sortable(): array
    {
        return [];
    }

    /**
     * @return array<string, callable(Builder, string): void>
     */
    protected function customSorts(): array
    {
        return [];
    }

    /**
     * @return array<string, callable(Builder, array<int, array<string, mixed>>, string):void>
     */
    protected function customFilters(): array
    {
        return [];
    }

    protected function afterLoad(Collection $rows): Collection
    {
        return $rows;
    }
}

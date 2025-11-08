<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Export;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use ZydLabs\LaravelDataTableKit\Contracts\DataTableExportable;
use ZydLabs\LaravelDataTableKit\Services\DataTable\DataTableRequest;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Export\Support\DataTableQueryExport;

abstract class BaseDataTableExport implements DataTableExportable
{
    /**
     * Kuyruğa alınma eşik değeri. 0 veya daha küçükse eşik devre dışı kalır.
     */
    protected int $queueThreshold = 10_000;

    /**
     * Export her durumda kuyruğa alınsın mı?
     */
    protected bool $forceQueue = false;

    final public function toResponse(DataTableRequest $request, Builder $query): Response
    {
        $export = new DataTableQueryExport(
            clone $query,
            $this->headings(),
            fn ($row) => $this->mapRow($row)
        );

        $fileName = $this->fileName();

        if ($this->shouldQueue($request, $query)) {
            Excel::queue($export, $fileName);

            return response()->json([
                'message' => __('Export işlemi kuyruğa alındı.'),
                'file' => $fileName,
            ], Response::HTTP_ACCEPTED);
        }

        return Excel::download($export, $fileName);
    }

    /**
     * @return array<int, string>
     */
    abstract protected function headings(): array;

    /**
     * @return array<int, mixed>
     */
    abstract protected function mapRow($row): array;

    protected function fileName(): string
    {
        return sprintf('export-%s.xlsx', now()->format('Ymd_His'));
    }

    protected function shouldQueue(DataTableRequest $request, Builder $query): bool
    {
        if ($this->forceQueue) {
            return true;
        }

        if ($this->queueThreshold <= 0) {
            return false;
        }

        $countQuery = clone $query;

        return $countQuery->count() > $this->queueThreshold;
    }
}


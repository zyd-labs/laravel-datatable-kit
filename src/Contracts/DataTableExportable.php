<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;
use ZydLabs\LaravelDataTableKit\Services\DataTable\DataTableRequest;

interface DataTableExportable
{
    public function toResponse(DataTableRequest $request, Builder $query): Response;
}


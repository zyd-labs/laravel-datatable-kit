<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Operations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Concerns\ResolvesRelations;

final class Sorter
{
    use ResolvesRelations;

    public function apply(Builder $query, string $field, int $order): void
    {
        $direction = $order === -1 ? 'desc' : 'asc';

        if (! str_contains($field, '.')) {
            $query->orderBy($field, $direction);

            return;
        }

        [$relationName, $column] = explode('.', $field, 2);

        $relation = $this->getRelationInstance($query->getModel(), $relationName);

        if ($relation === null) {
            $query->orderBy($field, $direction);

            return;
        }

        if ($relation instanceof BelongsTo) {
            $this->ensureJoinExists($query, $relation);
            $relatedTable = $relation->getRelated()->getTable();
            $query->orderBy("{$relatedTable}.{$column}", $direction);

            return;
        }

        if ($relation instanceof BelongsToMany) {
            $this->orderByBelongsToMany($query, $relation, $column, $direction);

            return;
        }

        if ($relation instanceof HasOne || $relation instanceof HasMany) {
            $this->orderByHasRelation($query, $relation, $column, $direction);

            return;
        }

        if ($relation instanceof MorphOne || $relation instanceof MorphMany) {
            $this->orderByMorphRelation($query, $relation, $column, $direction);

            return;
        }

        $query->orderBy($field, $direction);
    }

    private function orderByBelongsToMany(
        Builder $query,
        BelongsToMany $relation,
        string $column,
        string $direction
    ): void {
        $pivotTable = $relation->getTable();
        $relatedTable = $relation->getRelated()->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        $baseTable = $query->getModel()->getTable();
        $baseKey = $query->getModel()->getKeyName();

        $subquery = DB::table($relatedTable)
            ->select("{$relatedTable}.{$column}")
            ->join($pivotTable, "{$relatedTable}.{$relatedPivotKey}", '=', "{$pivotTable}.{$relatedPivotKey}")
            ->whereColumn("{$pivotTable}.{$foreignPivotKey}", "{$baseTable}.{$baseKey}")
            ->limit(1);

        $query->orderByRaw('('.$subquery->toSql().") {$direction}", $subquery->getBindings());
    }

    private function orderByHasRelation(
        Builder $query,
        HasOne|HasMany $relation,
        string $column,
        string $direction
    ): void {
        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $ownerKey = $relation->getLocalKeyName();
        $baseTable = $query->getModel()->getTable();

        $subquery = DB::table($relatedTable)
            ->select($column)
            ->whereColumn("{$relatedTable}.{$foreignKey}", "{$baseTable}.{$ownerKey}")
            ->limit(1);

        $query->orderByRaw('('.$subquery->toSql().") {$direction}", $subquery->getBindings());
    }

    private function orderByMorphRelation(
        Builder $query,
        MorphOne|MorphMany $relation,
        string $column,
        string $direction
    ): void {
        $relatedTable = $relation->getRelated()->getTable();
        $morphType = $relation->getMorphType();
        $morphKey = $relation->getForeignKeyName();
        $modelClass = get_class($query->getModel());
        $baseTable = $query->getModel()->getTable();
        $baseKey = $query->getModel()->getKeyName();

        $subquery = DB::table($relatedTable)
            ->select($column)
            ->whereColumn("{$relatedTable}.{$morphKey}", "{$baseTable}.{$baseKey}")
            ->where("{$relatedTable}.{$morphType}", $modelClass)
            ->limit(1);

        if (method_exists($query, 'orderBySub')) {
            $query->orderBySub($subquery, $direction);

            return;
        }

        $query->orderByRaw('('.$subquery->toSql().") {$direction}", $subquery->getBindings());
    }
}


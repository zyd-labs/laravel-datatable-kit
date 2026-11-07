<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\JoinClause;

trait ResolvesRelations
{
    protected function getRelationInstance(Model $model, string $relation): ?Relation
    {
        if (! $model->isRelation($relation)) {
            return null;
        }

        /** @var Relation $instance */
        $instance = $model->{$relation}();

        return $instance;
    }

    protected function ensureJoinExists(Builder $query, BelongsTo $relation, ?string $relationName = null): void
    {
        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $ownerKey = $relation->getOwnerKeyName();
        $baseTable = $query->getModel()->getTable();

        $relationName ??= method_exists($relation, 'getRelationName') ? $relation->getRelationName() : null;
        $trashedMode = $this->resolveBelongsToTrashedMode($relation);
        $alias = $this->getJoinAlias($relation, $relationName, $baseTable, $trashedMode);

        $joinTable = $alias !== $relatedTable
            ? sprintf('%s as %s', $relatedTable, $alias)
            : $relatedTable;

        $joins = $query->getQuery()->joins ?? [];
        foreach ($joins as $join) {
            if (is_object($join) && isset($join->table)) {
                $table = $join->table;

                if ($table === $joinTable || $table === $alias) {
                    return;
                }
            }
        }

        $deletedAtColumn = $this->softDeletedAtColumn($relation->getRelated());

        $query->leftJoin($joinTable, function (JoinClause $join) use ($baseTable, $foreignKey, $alias, $ownerKey, $deletedAtColumn, $trashedMode): void {
            $join->on("{$baseTable}.{$foreignKey}", '=', "{$alias}.{$ownerKey}");

            if ($deletedAtColumn === null || $trashedMode === 'with' || $trashedMode === 'none') {
                return;
            }

            if ($trashedMode === 'only') {
                $join->whereNotNull("{$alias}.{$deletedAtColumn}");

                return;
            }

            // Default SoftDeletes behaviour: exclude trashed related rows.
            $join->whereNull("{$alias}.{$deletedAtColumn}");
        });

        $columns = $query->getQuery()->columns;
        if ($columns === null || $columns === []) {
            $query->select("{$baseTable}.*");
        }
    }

    /**
     * @return 'none'|'without'|'with'|'only'
     */
    protected function resolveBelongsToTrashedMode(BelongsTo $relation): string
    {
        $deletedAtColumn = $this->softDeletedAtColumn($relation->getRelated());

        if ($deletedAtColumn === null) {
            return 'none';
        }

        $builder = $relation->getQuery();
        $softDeleteScopeRemoved = in_array(SoftDeletingScope::class, $builder->removedScopes(), true);

        if (! $softDeleteScopeRemoved) {
            return 'without';
        }

        if ($this->relationQueryRequiresOnlyTrashed($builder, $deletedAtColumn)) {
            return 'only';
        }

        return 'with';
    }

    protected function relationQueryRequiresOnlyTrashed(Builder $builder, string $deletedAtColumn): bool
    {
        $table = $builder->getModel()->getTable();
        $qualified = "{$table}.{$deletedAtColumn}";

        foreach ($builder->getQuery()->wheres ?? [] as $where) {
            if (($where['type'] ?? null) !== 'NotNull') {
                continue;
            }

            $column = (string) ($where['column'] ?? '');

            if ($column === $deletedAtColumn || $column === $qualified || str_ends_with($column, '.'.$deletedAtColumn)) {
                return true;
            }
        }

        return false;
    }

    protected function getJoinAlias(
        BelongsTo $relation,
        ?string $relationName,
        string $baseTable,
        string $trashedMode = 'none'
    ): string {
        $relatedTable = $relation->getRelated()->getTable();

        if ($relationName !== null && $relatedTable === $baseTable) {
            $alias = sprintf('%s_%s', $relationName, $relatedTable);
        } elseif (in_array($trashedMode, ['with', 'only'], true)) {
            // Distinct aliases when the same related table is joined under different trashed modes.
            $prefix = $relationName ?? $relatedTable;
            $alias = sprintf('%s__%s', $prefix, $trashedMode);
        } else {
            $alias = $relatedTable;
        }

        return $alias;
    }

    protected function softDeletedAtColumn(Model $related): ?string
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($related), true)) {
            return null;
        }

        if (! method_exists($related, 'getDeletedAtColumn')) {
            return 'deleted_at';
        }

        return $related->getDeletedAtColumn();
    }
}

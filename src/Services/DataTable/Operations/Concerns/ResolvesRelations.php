<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;

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
        $alias = $this->getJoinAlias($relation, $relationName, $baseTable);

        $joins = $query->getQuery()->joins ?? [];
        foreach ($joins as $join) {
            if (is_object($join) && isset($join->table)) {
                $table = $join->table;

                if ($table === $relatedTable || $table === sprintf('%s as %s', $relatedTable, $alias) || $table === $alias) {
                    return;
                }
            }
        }

        if ($alias !== $relatedTable) {
            $query->leftJoin(
                sprintf('%s as %s', $relatedTable, $alias),
                "{$baseTable}.{$foreignKey}",
                '=',
                "{$alias}.{$ownerKey}"
            );
        } else {
            $query->leftJoin(
                $relatedTable,
                "{$baseTable}.{$foreignKey}",
                '=',
                "{$relatedTable}.{$ownerKey}"
            );
        }

        $columns = $query->getQuery()->columns;
        if ($columns === null || $columns === []) {
            $query->select("{$baseTable}.*");
        }
    }

    protected function getJoinAlias(BelongsTo $relation, ?string $relationName, string $baseTable): string
    {
        $relatedTable = $relation->getRelated()->getTable();

        if ($relationName !== null && $relatedTable === $baseTable) {
            return sprintf('%s_%s', $relationName, $relatedTable);
        }

        return $relatedTable;
    }
}


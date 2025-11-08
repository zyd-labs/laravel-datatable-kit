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

    protected function ensureJoinExists(Builder $query, BelongsTo $relation): void
    {
        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $ownerKey = $relation->getOwnerKeyName();
        $baseTable = $query->getModel()->getTable();

        $joins = $query->getQuery()->joins ?? [];
        foreach ($joins as $join) {
            if (is_object($join) && isset($join->table) && $join->table === $relatedTable) {
                return;
            }
        }

        $query->leftJoin(
            $relatedTable,
            "{$baseTable}.{$foreignKey}",
            '=',
            "{$relatedTable}.{$ownerKey}"
        );

        $columns = $query->getQuery()->columns;
        if ($columns === null || $columns === []) {
            $query->select("{$baseTable}.*");
        }
    }
}


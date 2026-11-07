<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Operations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Concerns\ResolvesRelations;

final class GlobalSearchApplier
{
    use ResolvesRelations;

    /**
     * @param  array<int, string>  $fields
     */
    public function apply(Builder $query, ?string $term, array $fields): void
    {
        if ($term === null || trim($term) === '' || $fields === []) {
            return;
        }

        $model = $query->getModel();

        foreach ($fields as $field) {
            if (! str_contains($field, '.')) {
                continue;
            }

            [$relationName, $remainder] = explode('.', $field, 2);
            $relation = $this->getRelationInstance($model, $relationName);

            // Only join single-level BelongsTo fields; nested paths use whereHas.
            if ($relation instanceof BelongsTo && ! str_contains($remainder, '.')) {
                $this->ensureJoinExists($query, $relation, $relationName);
            }
        }

        $baseTable = $model->getTable();
        $query->where(function (Builder $nested) use ($term, $fields, $baseTable): void {
            foreach ($fields as $field) {
                if (str_contains($field, '.')) {
                    [$relationName, $column] = explode('.', $field, 2);
                    $this->applyRelationSearch($nested, $relationName, $column, $term);

                    continue;
                }

                $nested->orWhere("{$baseTable}.{$field}", 'like', "%{$term}%");
            }
        });
    }

    private function applyRelationSearch(Builder $query, string $relationName, string $column, string $term): void
    {
        /** @var Model $model */
        $model = $query->getModel();
        $relation = $this->getRelationInstance($model, $relationName);

        if ($relation === null) {
            return;
        }

        if (str_contains($column, '.')) {
            $query->orWhereHas($relationName, function (Builder $relQuery) use ($column, $term): void {
                $this->applyNestedRelationSearch($relQuery, $column, $term);
            });

            return;
        }

        if ($relation instanceof BelongsTo) {
            $baseTable = $model->getTable();
            $alias = $this->resolveBelongsToJoinAlias($relation, $relationName, $baseTable);

            $query->orWhere("{$alias}.{$column}", 'like', "%{$term}%");

            return;
        }

        // HasOne/HasMany/BelongsToMany/Morph* — whereHas preserves SoftDeletes,
        // global scopes, tenant scopes, and relation-level where clauses.
        $query->orWhereHas($relationName, function (Builder $relQuery) use ($column, $term): void {
            $relatedTable = $relQuery->getModel()->getTable();
            $relQuery->where("{$relatedTable}.{$column}", 'like', "%{$term}%");
        });
    }

    private function applyNestedRelationSearch(Builder $query, string $field, string $term): void
    {
        if (! str_contains($field, '.')) {
            $relatedTable = $query->getModel()->getTable();
            $query->where("{$relatedTable}.{$field}", 'like', "%{$term}%");

            return;
        }

        [$relation, $remaining] = explode('.', $field, 2);

        if ($this->getRelationInstance($query->getModel(), $relation) === null) {
            return;
        }

        $query->whereHas($relation, function (Builder $nested) use ($remaining, $term): void {
            $this->applyNestedRelationSearch($nested, $remaining, $term);
        });
    }
}

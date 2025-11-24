<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Operations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
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

            [$relationName] = explode('.', $field, 2);
            $relation = $this->getRelationInstance($model, $relationName);

            if ($relation instanceof BelongsTo) {
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

        $relatedTable = $relation->getRelated()->getTable();

        if ($relation instanceof BelongsTo) {
            $baseTable = $model->getTable();
            $alias = $this->getJoinAlias($relation, $relationName, $baseTable);

            $query->orWhere("{$alias}.{$column}", 'like', "%{$term}%");

            return;
        }

        if ($relation instanceof HasOne || $relation instanceof HasMany) {
            $foreignKey = $relation->getForeignKeyName();
            $localKey = $relation->getLocalKeyName();
            $baseTable = $model->getTable();

            $query->orWhereExists(function (Builder $subQuery) use ($relatedTable, $baseTable, $foreignKey, $localKey, $column, $term): void {
                $subQuery->select(DB::raw('1'))
                    ->from($relatedTable)
                    ->whereColumn("{$relatedTable}.{$foreignKey}", "{$baseTable}.{$localKey}")
                    ->where($column, 'like', "%{$term}%");
            });

            return;
        }

        if ($relation instanceof BelongsToMany) {
            $query->orWhereHas($relationName, function (Builder $relQuery) use ($column, $term): void {
                $relQuery->where($column, 'like', "%{$term}%");
            });

            return;
        }

        $query->orWhereHas($relationName, function (Builder $relQuery) use ($column, $term): void {
            $relQuery->where($column, 'like', "%{$term}%");
        });
    }
}


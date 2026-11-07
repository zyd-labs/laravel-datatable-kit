<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Operations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        $baseTable = $model->getTable();

        // Keep OR terms inside this group so outer tenant/authorization scopes stay intact.
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

        // BelongsTo/Has*/BTM/Morph* — whereHas preserves SoftDeletes, global scopes,
        // tenant scopes, and relation-level where clauses (including withTrashed/onlyTrashed).
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

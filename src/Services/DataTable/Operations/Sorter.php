<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Services\DataTable\Operations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Concerns\ResolvesRelations;

final class Sorter
{
    use ResolvesRelations;

    public function apply(Builder $query, string $field, string $direction): void
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

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

        if (
            $relation instanceof BelongsTo
            || $relation instanceof HasOne
            || $relation instanceof HasMany
            || $relation instanceof BelongsToMany
            || $relation instanceof MorphOne
            || $relation instanceof MorphMany
        ) {
            $this->orderByRelationColumn($query, $relationName, $column, $direction);

            return;
        }

        $query->orderBy($field, $direction);
    }

    /**
     * Order by a related column using an Eloquent relation subquery so SoftDeletes,
     * global scopes, and relation-level where clauses stay intact.
     *
     * Multi-row relations (HasMany/BelongsToMany/MorphMany) keep the historical
     * limit(1) semantics without an explicit secondary order — the chosen related
     * row is therefore database-dependent when multiple matches exist.
     */
    private function orderByRelationColumn(
        Builder $query,
        string $relationName,
        string $column,
        string $direction
    ): void {
        $subquery = $this->relationOrderSubquery($query, $relationName, $column);

        $query->orderBy($subquery, $direction);
    }

    private function relationOrderSubquery(Builder $parentQuery, string $relationName, string $column): Builder
    {
        /** @var Builder $subquery */
        $subquery = Relation::noConstraints(function () use ($parentQuery, $relationName, $column): Builder {
            $parent = $parentQuery->getModel();
            $relation = $parent->{$relationName}();
            $related = $relation->getRelated();
            $relatedTable = $related->getTable();
            $qualifiedColumn = "{$relatedTable}.{$column}";

            if ($relation instanceof BelongsTo) {
                return $relation->getQuery()
                    ->select($qualifiedColumn)
                    ->whereColumn(
                        $relation->getQualifiedOwnerKeyName(),
                        $parent->qualifyColumn($relation->getForeignKeyName())
                    )
                    ->limit(1);
            }

            if ($relation instanceof HasOne || $relation instanceof HasMany) {
                return $relation->getQuery()
                    ->select($qualifiedColumn)
                    ->whereColumn(
                        $relation->getQualifiedForeignKeyName(),
                        $parent->qualifyColumn($relation->getLocalKeyName())
                    )
                    ->limit(1);
            }

            if ($relation instanceof MorphOne || $relation instanceof MorphMany) {
                return $relation->getQuery()
                    ->select($qualifiedColumn)
                    ->whereColumn(
                        $relation->getQualifiedForeignKeyName(),
                        $parent->getQualifiedKeyName()
                    )
                    ->where($relation->getQualifiedMorphType(), $parent->getMorphClass())
                    ->limit(1);
            }

            if ($relation instanceof BelongsToMany) {
                // Under Relation::noConstraints, BelongsToMany::addConstraints() still
                // performJoin()s once but skips the parent-key where. Reuse that query so
                // wherePivot()/relation where()/SoftDeletes remain, and only correlate the
                // parent row — calling getRelationExistenceQuery() would join the pivot twice.
                return $relation->getQuery()
                    ->select($qualifiedColumn)
                    ->whereColumn(
                        $relation->getQualifiedParentKeyName(),
                        $relation->getQualifiedForeignPivotKeyName()
                    )
                    ->limit(1);
            }

            return $related->newQuery()
                ->select($qualifiedColumn)
                ->limit(1);
        });

        return $subquery;
    }
}

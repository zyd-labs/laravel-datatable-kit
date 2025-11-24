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
use Illuminate\Support\Facades\DB;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Concerns\ResolvesRelations;

final class FilterApplier
{
    use ResolvesRelations;

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @param  array<int, string>  $filterableFields
     */
    public function apply(Builder $query, array $filters, array $filterableFields, array $customFilters = []): void
    {
        foreach ($filters as $field => $filter) {
            if (! in_array($field, $filterableFields, true) || $field === 'global') {
                continue;
            }

            if (str_contains($field, '.')) {
                [$relationName] = explode('.', $field, 2);
                $relation = $this->getRelationInstance($query->getModel(), $relationName);

                if ($relation instanceof BelongsTo) {
                    $this->ensureJoinExists($query, $relation, $relationName);
                }
            }
        }

        foreach ($filters as $field => $filter) {
            if (! in_array($field, $filterableFields, true) || $field === 'global') {
                continue;
            }

            $operator = $filter['operator'] ?? 'and';
            $constraints = $filter['constraints'] ?? [];

            if (array_key_exists($field, $customFilters) && is_callable($customFilters[$field])) {
                $query->where(function (Builder $nested) use ($customFilters, $field, $constraints, $operator): void {
                    $customFilters[$field]($nested, $constraints, $operator);
                });

                continue;
            }

            $baseTable = $query->getModel()->getTable();
            $query->where(function (Builder $nested) use ($field, $constraints, $operator, $baseTable): void {
                foreach ($constraints as $index => $constraint) {
                    $value = $constraint['value'] ?? null;
                    $matchMode = $constraint['matchMode'] ?? 'contains';

                    if ($value === null && $matchMode !== 'equals') {
                        continue;
                    }

                    $method = ($operator === 'or' && $index > 0) ? 'orWhere' : 'where';

                    if (str_ends_with($field, '_count')) {
                        $relation = str_replace('_count', '', $field);
                        $this->applyCountFilter($nested, $relation, $matchMode, $value, $method);
                        continue;
                    }

                    if (str_contains($field, '.')) {
                        $this->applyRelationFilter($nested, $field, $matchMode, $value, $method);
                        continue;
                    }

                    // Direkt kolonlar için tablo prefix'i ekle (ambiguous hata önlemek için)
                    $this->applyMatchMode($nested, "{$baseTable}.{$field}", $matchMode, $value, $method);
                }
            });
        }
    }

    private function applyRelationFilter(
        Builder $query,
        string $field,
        string $matchMode,
        mixed $value,
        string $method
    ): void {
        [$relationName, $column] = explode('.', $field, 2);
        $relation = $this->getRelationInstance($query->getModel(), $relationName);

        if ($relation === null) {
            return;
        }

        if (str_contains($column, '.')) {
            $hasMethod = $method === 'orWhere' ? 'orWhereHas' : 'whereHas';
            $query->{$hasMethod}($relationName, function (Builder $relQuery) use ($column, $matchMode, $value): void {
                $this->applyNestedRelationFilter($relQuery, $column, $matchMode, $value);
            });

            return;
        }

        if ($relation instanceof BelongsTo) {
            $this->applyBelongsToFilter($query, $relation, $relationName, $column, $matchMode, $value, $method);

            return;
        }

        if ($relation instanceof HasOne || $relation instanceof HasMany) {
            $this->applyHasRelationFilter($query, $relation, $column, $matchMode, $value, $method);

            return;
        }

        if ($relation instanceof BelongsToMany) {
            $this->applyBelongsToManyFilter($query, $relationName, $column, $matchMode, $value, $method);

            return;
        }

        if ($relation instanceof MorphOne || $relation instanceof MorphMany) {
            $hasMethod = $method === 'orWhere' ? 'orWhereHasMorph' : 'whereHasMorph';
            $query->{$hasMethod}($relationName, '*', function (Builder $relQuery) use ($column, $matchMode, $value): void {
                $relQuery->where($column, $this->getMatchOperator($matchMode), $this->getMatchValue($matchMode, $value));
            });

            return;
        }

        $hasMethod = $method === 'orWhere' ? 'orWhereHas' : 'whereHas';
        $query->{$hasMethod}($relationName, function (Builder $relQuery) use ($column, $matchMode, $value): void {
            $relQuery->where($column, $this->getMatchOperator($matchMode), $this->getMatchValue($matchMode, $value));
        });
    }

    private function applyNestedRelationFilter(
        Builder $query,
        string $field,
        string $matchMode,
        mixed $value
    ): void {
        if (! str_contains($field, '.')) {
            $query->where($field, $this->getMatchOperator($matchMode), $this->getMatchValue($matchMode, $value));

            return;
        }

        [$relation, $remaining] = explode('.', $field, 2);

        $query->whereHas($relation, function (Builder $nested) use ($remaining, $matchMode, $value): void {
            $this->applyNestedRelationFilter($nested, $remaining, $matchMode, $value);
        });
    }

    private function applyBelongsToFilter(
        Builder $query,
        BelongsTo $relation,
        string $relationName,
        string $column,
        string $matchMode,
        mixed $value,
        string $method
    ): void {
        $this->ensureJoinExists($query, $relation, $relationName);

        $baseTable = $query->getModel()->getTable();
        $alias = $this->getJoinAlias($relation, $relationName, $baseTable);

        $query->{$method}(
            "{$alias}.{$column}",
            $this->getMatchOperator($matchMode),
            $this->getMatchValue($matchMode, $value)
        );
    }

    private function applyHasRelationFilter(
        Builder $query,
        HasOne|HasMany $relation,
        string $column,
        string $matchMode,
        mixed $value,
        string $method
    ): void {
        $model = $query->getModel();
        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $localKey = $relation->getLocalKeyName();
        $baseTable = $model->getTable();
        $existsMethod = $method === 'orWhere' ? 'orWhereExists' : 'whereExists';

        $query->{$existsMethod}(function (Builder $subQuery) use ($relatedTable, $baseTable, $foreignKey, $localKey, $column, $matchMode, $value): void {
            $subQuery->select(DB::raw('1'))
                ->from($relatedTable)
                ->whereColumn("{$relatedTable}.{$foreignKey}", "{$baseTable}.{$localKey}")
                ->where($column, $this->getMatchOperator($matchMode), $this->getMatchValue($matchMode, $value));
        });
    }

    private function applyBelongsToManyFilter(
        Builder $query,
        string $relationName,
        string $column,
        string $matchMode,
        mixed $value,
        string $method
    ): void {
        $hasMethod = $method === 'orWhere' ? 'orWhereHas' : 'whereHas';

        $query->{$hasMethod}($relationName, function (Builder $relQuery) use ($column, $matchMode, $value): void {
            $relQuery->where($column, $this->getMatchOperator($matchMode), $this->getMatchValue($matchMode, $value));
        }, '>=', 1);
    }

    private function applyCountFilter(
        Builder $query,
        string $relationName,
        string $matchMode,
        mixed $value,
        string $method
    ): void {
        $relation = $this->getRelationInstance($query->getModel(), $relationName);

        if ($relation === null) {
            return;
        }

        $operator = $this->getCountOperator($matchMode);

        if ($relation instanceof HasOne || $relation instanceof HasMany) {
            $relatedTable = $relation->getRelated()->getTable();
            $foreignKey = $relation->getForeignKeyName();
            $localKey = $relation->getLocalKeyName();
            $baseTable = $query->getModel()->getTable();

            $query->{$method}(function (Builder $where) use ($relatedTable, $baseTable, $foreignKey, $localKey, $operator, $value): void {
                $raw = "(SELECT COUNT(*) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$foreignKey}` = `{$baseTable}`.`{$localKey}`) {$operator} ?";
                $where->whereRaw(DB::raw($raw), [$value]);
            });

            return;
        }

        if ($relation instanceof BelongsTo) {
            $relatedTable = $relation->getRelated()->getTable();
            $foreignKey = $relation->getForeignKeyName();
            $ownerKey = $relation->getOwnerKeyName();
            $baseTable = $query->getModel()->getTable();

            $query->{$method}(function (Builder $where) use ($relatedTable, $baseTable, $foreignKey, $ownerKey, $operator, $value): void {
                $raw = "(SELECT COUNT(*) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$ownerKey}` = `{$baseTable}`.`{$foreignKey}`) {$operator} ?";
                $where->whereRaw(DB::raw($raw), [$value]);
            });

            return;
        }

        if ($relation instanceof BelongsToMany) {
            $pivotTable = $relation->getTable();
            $foreignPivotKey = $relation->getForeignPivotKeyName();
            $parentKey = $relation->getParent()->getKeyName();
            $baseTable = $query->getModel()->getTable();

            $query->{$method}(function (Builder $where) use ($pivotTable, $baseTable, $foreignPivotKey, $parentKey, $operator, $value): void {
                $raw = "(SELECT COUNT(*) FROM `{$pivotTable}` WHERE `{$pivotTable}`.`{$foreignPivotKey}` = `{$baseTable}`.`{$parentKey}`) {$operator} ?";
                $where->whereRaw(DB::raw($raw), [$value]);
            });

            return;
        }

        $hasMethod = $method === 'orWhere' ? 'orWhereHas' : 'whereHas';
        $query->{$hasMethod}($relationName, function (): void {
        }, $operator, $value);
    }

    private function applyMatchMode(
        Builder $query,
        string $field,
        string $matchMode,
        mixed $value,
        string $method = 'where'
    ): void {
        switch ($matchMode) {
            case 'contains':
                $query->{$method}($field, 'like', "%{$value}%");

                return;
            case 'notContains':
                $query->{$method}($field, 'not like', "%{$value}%");

                return;
            case 'notContains':
                $query->{$method}($field, ' not like', "%{$value}%");

                return;
            case 'startsWith':
                $query->{$method}($field, 'like', "{$value}%");

                return;
            case 'endsWith':
                $query->{$method}($field, 'like', "%{$value}");

                return;
            case 'equals':
                $query->{$method}($field, '=', $value);

                return;
            case 'notEquals':
                $query->{$method}($field, '!=', $value);

                return;
            case 'lt':
                $query->{$method}($field, '<', $value);

                return;
            case 'lte':
                $query->{$method}($field, '<=', $value);

                return;
            case 'gt':
                $query->{$method}($field, '>', $value);

                return;
            case 'gte':
                $query->{$method}($field, '>=', $value);

                return;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($field, $value);
                }

                return;
            case 'in':
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                }

                return;
            case 'dateIs':
                $query->{$method}($field, '=', $value);

                return;
            case 'dateIsNot':
                $query->{$method}($field, '!=', $value);

                return;
            case 'dateBefore':
                $query->{$method}($field, '<', $value);

                return;
            case 'dateAfter':
                $query->{$method}($field, '>', $value);

                return;
            case 'isNull':
                $query->{$method . 'Null'}($field);

                return;
            case 'isNotNull':
                $query->{$method . 'NotNull'}($field);

                return;
        }

        $query->{$method}($field, 'like', "%{$value}%");
    }

    private function getMatchOperator(string $matchMode): string
    {
        return match ($matchMode) {
            'contains', 'startsWith', 'endsWith' => 'like',
            'notContains' => 'not like',
            'equals' => '=',
            'notEquals' => '!=',
            'lt' => '<',
            'lte' => '<=',
            'gt' => '>',
            'gte' => '>=',
            default => '=',
        };
    }

    private function getMatchValue(string $matchMode, mixed $value): mixed
    {
        return match ($matchMode) {
            'contains' => "%{$value}%",
            'notContains' => "%{$value}%",
            'startsWith' => "{$value}%",
            'endsWith' => "%{$value}",
            default => $value,
        };
    }

    private function getCountOperator(string $matchMode): string
    {
        return match ($matchMode) {
            'equals' => '=',
            'notEquals' => '!=',
            'lt' => '<',
            'lte' => '<=',
            'gt' => '>',
            'gte' => '>=',
            default => '=',
        };
    }
}


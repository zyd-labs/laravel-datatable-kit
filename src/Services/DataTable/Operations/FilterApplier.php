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
     * Match modes that remain meaningful when the constraint value is null.
     *
     * @var list<string>
     */
    private const NULL_VALUE_MATCH_MODES = [
        'isNull',
        'isNotNull',
        'equals',
        'notEquals',
    ];

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @param  array<int, string>  $filterableFields
     * @param  array<string, callable>  $customFilters
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

            $operator = ($filter['operator'] ?? 'and') === 'or' ? 'or' : 'and';
            $constraints = $filter['constraints'] ?? [];

            if (array_key_exists($field, $customFilters) && is_callable($customFilters[$field])) {
                $query->where(function (Builder $nested) use ($customFilters, $field, $constraints, $operator): void {
                    $customFilters[$field]($nested, $constraints, $operator);
                });

                continue;
            }

            $baseTable = $query->getModel()->getTable();
            $applicable = [];

            foreach ($constraints as $constraint) {
                if (! is_array($constraint)) {
                    continue;
                }

                $value = array_key_exists('value', $constraint) ? $constraint['value'] : null;
                $matchMode = (string) ($constraint['matchMode'] ?? 'contains');

                if ($this->shouldSkipConstraint($matchMode, $value)) {
                    continue;
                }

                $applicable[] = [
                    'matchMode' => $matchMode,
                    'value' => $value,
                ];
            }

            if ($applicable === []) {
                continue;
            }

            // Group constraints so OR never escapes surrounding tenant/authorization scopes.
            $query->where(function (Builder $nested) use ($field, $applicable, $operator, $baseTable): void {
                foreach ($applicable as $index => $constraint) {
                    $matchMode = $constraint['matchMode'];
                    $value = $constraint['value'];
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

                    $this->applyMatchMode($nested, "{$baseTable}.{$field}", $matchMode, $value, $method);
                }
            });
        }
    }

    private function shouldSkipConstraint(string $matchMode, mixed $value): bool
    {
        if (in_array($matchMode, ['isNull', 'isNotNull'], true)) {
            return false;
        }

        if ($value === null) {
            return ! in_array($matchMode, self::NULL_VALUE_MATCH_MODES, true);
        }

        if ($matchMode === 'between') {
            return ! $this->isValidBetweenValue($value);
        }

        if ($matchMode === 'in') {
            // Non-arrays are ignored; empty arrays are applied as a never-matching predicate.
            return ! is_array($value);
        }

        return false;
    }

    private function isValidBetweenValue(mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 2) {
            return false;
        }

        $bounds = array_values($value);

        return $bounds[0] !== null && $bounds[0] !== ''
            && $bounds[1] !== null && $bounds[1] !== '';
    }

    /**
     * @return list<mixed>
     */
    private function normalizeInValue(array $value): array
    {
        $items = [];

        foreach ($value as $item) {
            if ($item === null) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
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
                $relatedTable = $relQuery->getModel()->getTable();
                $this->applyMatchMode($relQuery, "{$relatedTable}.{$column}", $matchMode, $value, 'where');
            });

            return;
        }

        $hasMethod = $method === 'orWhere' ? 'orWhereHas' : 'whereHas';
        $query->{$hasMethod}($relationName, function (Builder $relQuery) use ($column, $matchMode, $value): void {
            $relatedTable = $relQuery->getModel()->getTable();
            $this->applyMatchMode($relQuery, "{$relatedTable}.{$column}", $matchMode, $value, 'where');
        });
    }

    private function applyNestedRelationFilter(
        Builder $query,
        string $field,
        string $matchMode,
        mixed $value
    ): void {
        if (! str_contains($field, '.')) {
            $relatedTable = $query->getModel()->getTable();
            $this->applyMatchMode($query, "{$relatedTable}.{$field}", $matchMode, $value, 'where');

            return;
        }

        [$relation, $remaining] = explode('.', $field, 2);

        if ($this->getRelationInstance($query->getModel(), $relation) === null) {
            return;
        }

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

        $this->applyMatchMode($query, "{$alias}.{$column}", $matchMode, $value, $method);
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

        $query->{$existsMethod}(function ($subQuery) use ($relatedTable, $baseTable, $foreignKey, $localKey, $column, $matchMode, $value): void {
            $subQuery->select(DB::raw('1'))
                ->from($relatedTable)
                ->whereColumn("{$relatedTable}.{$foreignKey}", "{$baseTable}.{$localKey}");

            $this->applyMatchModeOnQueryBuilder(
                $subQuery,
                "{$relatedTable}.{$column}",
                $matchMode,
                $value,
                'where'
            );
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
            $relatedTable = $relQuery->getModel()->getTable();
            $this->applyMatchMode($relQuery, "{$relatedTable}.{$column}", $matchMode, $value, 'where');
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
        $query->{$hasMethod}($relationName, function (): void {}, $operator, $value);
    }

    private function applyMatchMode(
        Builder $query,
        string $field,
        string $matchMode,
        mixed $value,
        string $method = 'where'
    ): void {
        $this->applyMatchModeOnQueryBuilder($query, $field, $matchMode, $value, $method);
    }

    /**
     * Shared match-mode application for Eloquent builders and nested query builders
     * (e.g. whereExists closures).
     */
    private function applyMatchModeOnQueryBuilder(
        object $query,
        string $field,
        string $matchMode,
        mixed $value,
        string $method = 'where'
    ): void {
        switch ($matchMode) {
            case 'contains':
                $query->{$method}($field, $this->likeOperator(), "%{$value}%");

                return;
            case 'notContains':
                $query->{$method}($field, $this->likeOperator(negative: true), "%{$value}%");

                return;
            case 'startsWith':
                $query->{$method}($field, $this->likeOperator(), "{$value}%");

                return;
            case 'endsWith':
                $query->{$method}($field, $this->likeOperator(), "%{$value}");

                return;
            case 'equals':
                if ($value === null) {
                    $query->{$method.'Null'}($field);

                    return;
                }

                $query->{$method}($field, '=', $value);

                return;
            case 'notEquals':
                if ($value === null) {
                    $query->{$method.'NotNull'}($field);

                    return;
                }

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
                if (! $this->isValidBetweenValue($value)) {
                    return;
                }

                $betweenMethod = $method === 'orWhere' ? 'orWhereBetween' : 'whereBetween';
                $query->{$betweenMethod}($field, array_values($value));

                return;
            case 'in':
                if (! is_array($value)) {
                    return;
                }

                $inMethod = $method === 'orWhere' ? 'orWhereIn' : 'whereIn';
                // Empty arrays become a never-matching predicate in Laravel (0 = 1).
                $query->{$inMethod}($field, $this->normalizeInValue($value));

                return;
            case 'dateIs':
                $query->{$method.'Date'}($field, '=', $value);

                return;
            case 'dateIsNot':
                $query->{$method.'Date'}($field, '!=', $value);

                return;
            case 'dateBefore':
                $query->{$method.'Date'}($field, '<', $value);

                return;
            case 'dateAfter':
                $query->{$method.'Date'}($field, '>', $value);

                return;
            case 'isNull':
                $query->{$method.'Null'}($field);

                return;
            case 'isNotNull':
                $query->{$method.'NotNull'}($field);

                return;
        }

        $query->{$method}($field, $this->likeOperator(), "%{$value}%");
    }

    private function getMatchOperator(string $matchMode): string
    {
        return match ($matchMode) {
            'contains', 'startsWith', 'endsWith' => $this->likeOperator(),
            'notContains' => $this->likeOperator(negative: true),
            'equals' => '=',
            'notEquals' => '!=',
            'lt' => '<',
            'lte' => '<=',
            'gt' => '>',
            'gte' => '>=',
            default => '=',
        };
    }

    /**
     * Get the appropriate LIKE operator based on the database driver.
     * PostgreSQL uses ILIKE for case-insensitive searches, while other databases use LIKE.
     */
    private function likeOperator(bool $negative = false): string
    {
        $isPostgreSQL = DB::connection()->getDriverName() === 'pgsql';

        if ($isPostgreSQL) {
            return $negative ? 'not ilike' : 'ilike';
        }

        return $negative ? 'not like' : 'like';
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

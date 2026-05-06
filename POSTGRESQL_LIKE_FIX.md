# PostgreSQL Case-Sensitivity Fix for FilterApplier

## Problem
PostgreSQL's LIKE operator is case-sensitive by default, while MySQL/MariaDB's LIKE is case-insensitive. This caused DataTable filters to behave inconsistently across different databases:
- MySQL: Searching for "Ahmet" finds "ahmet" ✓
- PostgreSQL: Searching for "Ahmet" does NOT find "ahmet" ✗

## Solution
Added a database-aware operator selection system that:
1. Detects the active database driver using `DB::connection()->getDriverName()`
2. Uses `ILIKE` (case-insensitive) for PostgreSQL
3. Uses `LIKE` (case-insensitive) for MySQL, MariaDB, and other databases

## Implementation Details

### New Method: `likeOperator(bool $negative = false): string`
Located in `FilterApplier.php`, this private method returns the appropriate operator:

```php
private function likeOperator(bool $negative = false): string
{
    $isPostgreSQL = DB::connection()->getDriverName() === 'pgsql';

    if ($isPostgreSQL) {
        return $negative ? 'not ilike' : 'ilike';
    }

    return $negative ? 'not like' : 'like';
}
```

### Updated Methods
1. **`applyMatchMode()`** - Updated to use `likeOperator()` for:
   - `contains` operator → `ilike`/`like`
   - `notContains` operator → `not ilike`/`not like`
   - `startsWith` operator → `ilike`/`like`
   - `endsWith` operator → `ilike`/`like`
   - Fallback case → `ilike`/`like`

2. **`getMatchOperator()`** - Updated to use `likeOperator()` for:
   - `contains`, `startsWith`, `endsWith` → `ilike`/`like`
   - `notContains` → `not ilike`/`not like`

### Key Features
- ✓ No SQL injection risk - query builder handles all parameter binding
- ✓ No changes to public API - all modifications are internal
- ✓ Works with all filter types:
  - Direct column filters
  - Relation filters (BelongsTo, HasOne, HasMany, BelongsToMany, Morph)
  - Nested relation filters
  - Count filters
- ✓ MySQL/MariaDB compatibility maintained - uses existing `like`/`not like`
- ✓ PostgreSQL case-insensitive search support

## Testing

### Running Tests
```bash
# Run all FilterApplier tests
php vendor/bin/phpunit tests/Unit/Operations/FilterApplierTest.php

# Run specific test
php vendor/bin/phpunit tests/Unit/Operations/FilterApplierTest.php::FilterApplierTest::test_postgresql_driver_uses_ilike_for_contains_operator
```

### Test Coverage
The test suite includes:
1. ✓ PostgreSQL uses `ILIKE` for contains
2. ✓ PostgreSQL uses `NOT ILIKE` for notContains
3. ✓ PostgreSQL uses `ILIKE` for startsWith
4. ✓ PostgreSQL uses `ILIKE` for endsWith
5. ✓ MySQL uses `LIKE` for contains
6. ✓ MySQL uses `NOT LIKE` for notContains
7. ✓ SQLite uses `LIKE` (default behavior)
8. ✓ Equals operator not affected by driver changes
9. ✓ Match value formatting preserved

## Usage Example

Before (PostgreSQL case-sensitive):
```php
// Searching for "Ahmet" would NOT find "ahmet"
$filters = [
    'name' => [
        'operator' => 'and',
        'constraints' => [
            ['value' => 'Ahmet', 'matchMode' => 'contains']
        ]
    ]
];
```

After (PostgreSQL case-insensitive):
```php
// Searching for "Ahmet" WILL find "ahmet" thanks to ILIKE
$filters = [
    'name' => [
        'operator' => 'and',
        'constraints' => [
            ['value' => 'Ahmet', 'matchMode' => 'contains']
        ]
    ]
];
// Generated SQL for PostgreSQL: WHERE name ILIKE '%Ahmet%'
```

## Database Support
- ✓ PostgreSQL (ILIKE/NOT ILIKE)
- ✓ MySQL (LIKE/NOT LIKE) - case-insensitive by default
- ✓ MariaDB (LIKE/NOT LIKE) - case-insensitive by default
- ✓ SQLite (LIKE/NOT LIKE)
- ✓ SQL Server (LIKE/NOT LIKE)
- ✓ Other databases using standard SQL LIKE operator

## Notes
- The fix is implemented at the database query layer
- No frontend or controller-level changes required
- All existing code continues to work without modification
- The change is backward compatible with existing filtering behavior

# PostgreSQL LIKE Fix - Practical Examples & Edge Cases

## Practical Examples

### Example 1: Simple Contains Filter

**PostgreSQL Before Fix:**
```sql
SELECT * FROM users WHERE name LIKE '%Ahmet%';
-- Result: Only finds "Ahmet", "AHMET" (exact case match)
```

**PostgreSQL After Fix:**
```sql
SELECT * FROM users WHERE name ILIKE '%Ahmet%';
-- Result: Finds "Ahmet", "ahmet", "AHMET", "AhmeT" (case-insensitive)
```

**MySQL (Unchanged):**
```sql
SELECT * FROM users WHERE name LIKE '%Ahmet%';
-- Result: Always case-insensitive by default
```

---

### Example 2: Not Contains Filter

**PostgreSQL Before Fix:**
```sql
SELECT * FROM users WHERE name NOT LIKE '%Ahmet%';
-- Problem: Also breaks case-sensitivity
```

**PostgreSQL After Fix:**
```sql
SELECT * FROM users WHERE name NOT ILIKE '%Ahmet%';
-- Result: Correct case-insensitive exclusion
```

---

### Example 3: Starts With Filter

**PostgreSQL Before Fix:**
```sql
SELECT * FROM users WHERE email LIKE 'admin@%';
-- Result: Only finds "admin@domain.com", misses "Admin@domain.com"
```

**PostgreSQL After Fix:**
```sql
SELECT * FROM users WHERE email ILIKE 'admin@%';
-- Result: Finds all email patterns regardless of case
```

---

### Example 4: Relation Filters (BelongsTo)

**Filter Definition:**
```php
$filters = [
    'company.name' => [
        'operator' => 'and',
        'constraints' => [
            ['value' => 'Tech', 'matchMode' => 'contains']
        ]
    ]
];
```

**PostgreSQL Before Fix:**
```sql
SELECT * FROM users 
INNER JOIN companies ON users.company_id = companies.id 
WHERE companies.name LIKE '%Tech%';
-- Result: Only finds "Tech", misses "tech", "TECH"
```

**PostgreSQL After Fix:**
```sql
SELECT * FROM users 
INNER JOIN companies ON users.company_id = companies.id 
WHERE companies.name ILIKE '%Tech%';
-- Result: Finds all company names with "Tech" regardless of case
```

---

### Example 5: Nested Relation Filters

**Filter Definition:**
```php
$filters = [
    'company.department.name' => [
        'operator' => 'and',
        'constraints' => [
            ['value' => 'HR', 'matchMode' => 'startsWith']
        ]
    ]
];
```

**PostgreSQL Before Fix:**
```sql
SELECT * FROM users 
WHERE EXISTS (
    SELECT 1 FROM companies 
    WHERE companies.id = users.company_id
    AND EXISTS (
        SELECT 1 FROM departments 
        WHERE departments.company_id = companies.id
        AND departments.name LIKE 'HR%'
    )
);
-- Result: Only finds "HR Department", misses "hr department"
```

**PostgreSQL After Fix:**
```sql
SELECT * FROM users 
WHERE EXISTS (
    SELECT 1 FROM companies 
    WHERE companies.id = users.company_id
    AND EXISTS (
        SELECT 1 FROM departments 
        WHERE departments.company_id = companies.id
        AND departments.name ILIKE 'HR%'
    )
);
-- Result: Finds all department names starting with "HR" regardless of case
```

---

### Example 6: HasMany Relation Filters

**Filter Definition:**
```php
$filters = [
    'orders.status' => [
        'operator' => 'and',
        'constraints' => [
            ['value' => 'pending', 'matchMode' => 'contains']
        ]
    ]
];
```

**Generated SQL on PostgreSQL (After Fix):**
```sql
SELECT * FROM users 
WHERE EXISTS (
    SELECT 1 FROM orders 
    WHERE orders.user_id = users.id 
    AND orders.status ILIKE '%pending%'
);
```

---

### Example 7: BelongsToMany Relation Filters

**Filter Definition:**
```php
$filters = [
    'roles.name' => [
        'operator' => 'and',
        'constraints' => [
            ['value' => 'admin', 'matchMode' => 'contains']
        ]
    ]
];
```

**Generated SQL on PostgreSQL (After Fix):**
```sql
SELECT * FROM users 
WHERE EXISTS (
    SELECT 1 FROM roles 
    INNER JOIN role_user ON roles.id = role_user.role_id 
    WHERE role_user.user_id = users.id 
    AND roles.name ILIKE '%admin%'
);
```

---

## Edge Cases

### Edge Case 1: Special Characters

**Filter:** `'@dmin'`

**PostgreSQL:**
```sql
-- Before: WHERE username LIKE '%@dmin%'    (case-sensitive)
-- After:  WHERE username ILIKE '%@dmin%'   (case-insensitive, special char safe)
```

Special characters (!, @, #, $, %, etc.) are not affected by LIKE/ILIKE case-sensitivity.

---

### Edge Case 2: Unicode Characters

**Filter:** `'Müller'` (searching for German umlaut)

**PostgreSQL:**
```sql
-- Before: WHERE name LIKE '%Müller%'    (finds only "Müller")
-- After:  WHERE name ILIKE '%Müller%'   (finds "müller", "MÜLLER" too)
```

The fix properly handles Unicode characters with ILIKE.

---

### Edge Case 3: Whitespace

**Filter:** `'  test  '` (with leading/trailing spaces)

**PostgreSQL:**
```sql
-- Before: WHERE title LIKE '%  test  %'  (exact whitespace match)
-- After:  WHERE title ILIKE '%  test  %' (case-insensitive, whitespace exact)
```

Whitespace is preserved and matched exactly (only case is made insensitive).

---

### Edge Case 4: Empty String

**Filter:** `''` (empty search)

**PostgreSQL:**
```sql
-- Before: WHERE name LIKE '%%'    (matches everything)
-- After:  WHERE name ILIKE '%%'   (matches everything, case-insensitive)
```

Empty string behavior is preserved.

---

### Edge Case 5: NOT Operators

**Filter:** `matchMode: 'notContains'`, value: `'test'`

**PostgreSQL:**
```sql
-- Before: WHERE name NOT LIKE '%test%'    (case-sensitive exclusion)
-- After:  WHERE name NOT ILIKE '%test%'   (case-insensitive exclusion)
```

All NOT operators are properly handled.

---

### Edge Case 6: Mixed Case with Wildcards

**Filter:** `'%Test%'` (user includes wildcard in search)

**PostgreSQL:**
```sql
-- Before: WHERE name LIKE '%%%Test%%%'    (three wildcards, case-sensitive)
-- After:  WHERE name ILIKE '%%%Test%%%'   (three wildcards, case-insensitive)
```

Wildcards are properly escaped and case behavior is consistent.

---

### Edge Case 7: Multiple Filters (OR condition)

**Filter Definition:**
```php
$filters = [
    'name' => [
        'operator' => 'or',  // OR condition!
        'constraints' => [
            ['value' => 'John', 'matchMode' => 'contains'],
            ['value' => 'Jane', 'matchMode' => 'contains']
        ]
    ]
];
```

**PostgreSQL After Fix:**
```sql
SELECT * FROM users 
WHERE (
    name ILIKE '%John%'
    OR name ILIKE '%Jane%'
);
-- Result: Finds "john", "jane", "JOHN", "JANE", etc.
```

---

### Edge Case 8: Combined Match Modes

**Filter Definition:**
```php
$filters = [
    'name' => [
        'operator' => 'and',
        'constraints' => [
            ['value' => 'admin', 'matchMode' => 'startsWith'],
            ['value' => 'user', 'matchMode' => 'notContains']
        ]
    ]
];
```

**PostgreSQL After Fix:**
```sql
SELECT * FROM users 
WHERE (
    name ILIKE 'admin%'
    AND name NOT ILIKE '%user%'
);
-- Result: Finds "admin_panel", "ADMIN_system" but not "admin_user"
```

---

## Performance Notes

### Query Performance
- ILIKE vs LIKE performance is virtually identical in PostgreSQL
- Both require sequential scan without proper indexing
- Both benefit from indexes (though pattern matching limits effectiveness)

### Index Considerations

For PostgreSQL ILIKE to use indexes:
```sql
-- Create a case-insensitive index for better performance
CREATE INDEX idx_name_lower ON users(LOWER(name));

-- Or use a case-insensitive collation
CREATE INDEX idx_name_ci ON users(name COLLATE "C");
```

But this is optional - the fix works without indexes.

---

## Compatibility Matrix

| Scenario | PostgreSQL | MySQL | MariaDB | SQLite | SQL Server |
|----------|-----------|-------|---------|---------|-----------|
| contains | ✓ ILIKE | ✓ LIKE | ✓ LIKE | ✓ LIKE | ✓ LIKE |
| notContains | ✓ NOT ILIKE | ✓ NOT LIKE | ✓ NOT LIKE | ✓ NOT LIKE | ✓ NOT LIKE |
| startsWith | ✓ ILIKE | ✓ LIKE | ✓ LIKE | ✓ LIKE | ✓ LIKE |
| endsWith | ✓ ILIKE | ✓ LIKE | ✓ LIKE | ✓ LIKE | ✓ LIKE |
| Relations | ✓ ILIKE | ✓ LIKE | ✓ LIKE | ✓ LIKE | ✓ LIKE |
| Case-insensitive | ✓ YES | ✓ YES | ✓ YES | ✓ YES | ✓ YES |

---

## Troubleshooting

### Issue: Filter still case-sensitive after update

**Solution:**
1. Clear Laravel cache: `php artisan cache:clear`
2. Verify database driver: `dd(DB::connection()->getDriverName())`
3. Check if connection name is correct in config/database.php

### Issue: OR filters not working correctly

**Solution:**
The `'operator' => 'or'` works with multiple constraints. Each constraint is properly converted with ILIKE on PostgreSQL.

### Issue: Relation filters not working

**Solution:**
All relation types (BelongsTo, HasOne, HasMany, BelongsToMany, MorphOne, MorphMany) use the same `getMatchOperator()` method, so they all benefit from the fix automatically.

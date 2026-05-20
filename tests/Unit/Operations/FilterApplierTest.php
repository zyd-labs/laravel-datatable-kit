<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Unit\Operations;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\FilterApplier;

class FilterApplierTest extends TestCase
{
    private FilterApplier $filterApplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filterApplier = new FilterApplier;
    }

    /**
     * Test that PostgreSQL driver uses ILIKE for contains operator
     */
    public function test_postgresql_driver_uses_ilike_for_contains_operator(): void
    {
        // Mock the database connection to return PostgreSQL driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('pgsql');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access and test the private likeOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('likeOperator');
        $method->setAccessible(true);

        // Test normal search (negative = false)
        $result = $method->invoke($this->filterApplier, false);
        $this->assertEquals('ilike', $result, 'PostgreSQL should use "ilike" for normal searches');

        // Test negative search (negative = true)
        $result = $method->invoke($this->filterApplier, true);
        $this->assertEquals('not ilike', $result, 'PostgreSQL should use "not ilike" for negative searches');
    }

    /**
     * Test that PostgreSQL driver uses ILIKE for notContains operator
     */
    public function test_postgresql_driver_uses_not_ilike_for_not_contains_operator(): void
    {
        // Mock the database connection to return PostgreSQL driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('pgsql');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access the getMatchOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('getMatchOperator');
        $method->setAccessible(true);

        $result = $method->invoke($this->filterApplier, 'notContains');
        $this->assertEquals('not ilike', $result, 'PostgreSQL should use "not ilike" for notContains');
    }

    /**
     * Test that MySQL driver uses LIKE for contains operator
     */
    public function test_mysql_driver_uses_like_for_contains_operator(): void
    {
        // Mock the database connection to return MySQL driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('mysql');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access and test the private likeOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('likeOperator');
        $method->setAccessible(true);

        // Test normal search (negative = false)
        $result = $method->invoke($this->filterApplier, false);
        $this->assertEquals('like', $result, 'MySQL should use "like" for normal searches');

        // Test negative search (negative = true)
        $result = $method->invoke($this->filterApplier, true);
        $this->assertEquals('not like', $result, 'MySQL should use "not like" for negative searches');
    }

    /**
     * Test that MySQL driver uses LIKE for notContains operator
     */
    public function test_mysql_driver_uses_not_like_for_not_contains_operator(): void
    {
        // Mock the database connection to return MySQL driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('mysql');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access the getMatchOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('getMatchOperator');
        $method->setAccessible(true);

        $result = $method->invoke($this->filterApplier, 'notContains');
        $this->assertEquals('not like', $result, 'MySQL should use "not like" for notContains');
    }

    /**
     * Test that contains operator uses correct operator for PostgreSQL
     */
    public function test_contains_operator_for_postgresql(): void
    {
        // Mock the database connection to return PostgreSQL driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('pgsql');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access the getMatchOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('getMatchOperator');
        $method->setAccessible(true);

        $result = $method->invoke($this->filterApplier, 'contains');
        $this->assertEquals('ilike', $result, 'PostgreSQL should use "ilike" for contains');
    }

    /**
     * Test that startsWith operator uses correct operator for PostgreSQL
     */
    public function test_starts_with_operator_for_postgresql(): void
    {
        // Mock the database connection to return PostgreSQL driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('pgsql');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access the getMatchOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('getMatchOperator');
        $method->setAccessible(true);

        $result = $method->invoke($this->filterApplier, 'startsWith');
        $this->assertEquals('ilike', $result, 'PostgreSQL should use "ilike" for startsWith');
    }

    /**
     * Test that endsWith operator uses correct operator for PostgreSQL
     */
    public function test_ends_with_operator_for_postgresql(): void
    {
        // Mock the database connection to return PostgreSQL driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('pgsql');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access the getMatchOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('getMatchOperator');
        $method->setAccessible(true);

        $result = $method->invoke($this->filterApplier, 'endsWith');
        $this->assertEquals('ilike', $result, 'PostgreSQL should use "ilike" for endsWith');
    }

    /**
     * Test that SQLite driver uses LIKE (default behavior)
     */
    public function test_sqlite_driver_uses_like(): void
    {
        // Mock the database connection to return SQLite driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('sqlite');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access and test the private likeOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('likeOperator');
        $method->setAccessible(true);

        // Test normal search (negative = false)
        $result = $method->invoke($this->filterApplier, false);
        $this->assertEquals('like', $result, 'SQLite should use "like" for normal searches');

        // Test negative search (negative = true)
        $result = $method->invoke($this->filterApplier, true);
        $this->assertEquals('not like', $result, 'SQLite should use "not like" for negative searches');
    }

    /**
     * Test that getMatchValue preserves the correct value formatting
     */
    public function test_get_match_value_formatting(): void
    {
        // Use reflection to access the getMatchValue method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('getMatchValue');
        $method->setAccessible(true);

        $testValue = 'test';

        $this->assertEquals("%{$testValue}%", $method->invoke($this->filterApplier, 'contains', $testValue));
        $this->assertEquals("%{$testValue}%", $method->invoke($this->filterApplier, 'notContains', $testValue));
        $this->assertEquals("{$testValue}%", $method->invoke($this->filterApplier, 'startsWith', $testValue));
        $this->assertEquals("%{$testValue}", $method->invoke($this->filterApplier, 'endsWith', $testValue));
        $this->assertEquals($testValue, $method->invoke($this->filterApplier, 'equals', $testValue));
    }

    /**
     * Test that equals operator is not affected by driver change
     */
    public function test_equals_operator_not_affected_by_driver(): void
    {
        // Mock the database connection to return PostgreSQL driver
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockConnection->method('getDriverName')->willReturn('pgsql');

        DB::shouldReceive('connection')->andReturn($mockConnection);

        // Use reflection to access the getMatchOperator method
        $reflectionClass = new \ReflectionClass($this->filterApplier);
        $method = $reflectionClass->getMethod('getMatchOperator');
        $method->setAccessible(true);

        $result = $method->invoke($this->filterApplier, 'equals');
        $this->assertEquals('=', $result, 'Equals operator should always be "="');
    }
}

<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Unit\Operations;

use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionClass;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\FilterApplier;
use ZydLabs\LaravelDataTableKit\Tests\TestCase;

class FilterApplierTest extends TestCase
{
    private FilterApplier $filterApplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filterApplier = new FilterApplier;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_postgresql_driver_uses_ilike_for_contains_operator(): void
    {
        $this->stubConnectionDriver('pgsql');

        $method = $this->privateMethod('likeOperator');

        $this->assertSame('ilike', $method->invoke($this->filterApplier, false));
        $this->assertSame('not ilike', $method->invoke($this->filterApplier, true));
    }

    public function test_postgresql_driver_uses_not_ilike_for_not_contains_operator(): void
    {
        $this->stubConnectionDriver('pgsql');

        $method = $this->privateMethod('getMatchOperator');

        $this->assertSame('not ilike', $method->invoke($this->filterApplier, 'notContains'));
    }

    public function test_mysql_driver_uses_like_for_contains_operator(): void
    {
        $this->stubConnectionDriver('mysql');

        $method = $this->privateMethod('likeOperator');

        $this->assertSame('like', $method->invoke($this->filterApplier, false));
        $this->assertSame('not like', $method->invoke($this->filterApplier, true));
    }

    public function test_mysql_driver_uses_not_like_for_not_contains_operator(): void
    {
        $this->stubConnectionDriver('mysql');

        $method = $this->privateMethod('getMatchOperator');

        $this->assertSame('not like', $method->invoke($this->filterApplier, 'notContains'));
    }

    public function test_contains_operator_for_postgresql(): void
    {
        $this->stubConnectionDriver('pgsql');

        $method = $this->privateMethod('getMatchOperator');

        $this->assertSame('ilike', $method->invoke($this->filterApplier, 'contains'));
    }

    public function test_starts_with_operator_for_postgresql(): void
    {
        $this->stubConnectionDriver('pgsql');

        $method = $this->privateMethod('getMatchOperator');

        $this->assertSame('ilike', $method->invoke($this->filterApplier, 'startsWith'));
    }

    public function test_ends_with_operator_for_postgresql(): void
    {
        $this->stubConnectionDriver('pgsql');

        $method = $this->privateMethod('getMatchOperator');

        $this->assertSame('ilike', $method->invoke($this->filterApplier, 'endsWith'));
    }

    public function test_sqlite_driver_uses_like(): void
    {
        $this->stubConnectionDriver('sqlite');

        $method = $this->privateMethod('likeOperator');

        $this->assertSame('like', $method->invoke($this->filterApplier, false));
        $this->assertSame('not like', $method->invoke($this->filterApplier, true));
    }

    public function test_get_match_value_formatting(): void
    {
        $method = $this->privateMethod('getMatchValue');
        $testValue = 'test';

        $this->assertSame("%{$testValue}%", $method->invoke($this->filterApplier, 'contains', $testValue));
        $this->assertSame("%{$testValue}%", $method->invoke($this->filterApplier, 'notContains', $testValue));
        $this->assertSame("{$testValue}%", $method->invoke($this->filterApplier, 'startsWith', $testValue));
        $this->assertSame("%{$testValue}", $method->invoke($this->filterApplier, 'endsWith', $testValue));
        $this->assertSame($testValue, $method->invoke($this->filterApplier, 'equals', $testValue));
    }

    public function test_equals_operator_not_affected_by_driver(): void
    {
        $this->stubConnectionDriver('pgsql');

        $method = $this->privateMethod('getMatchOperator');

        $this->assertSame('=', $method->invoke($this->filterApplier, 'equals'));
    }

    private function stubConnectionDriver(string $driver): void
    {
        // ConnectionInterface no longer declares getDriverName; stub a plain object instead.
        $connection = new class($driver)
        {
            public function __construct(private string $driver) {}

            public function getDriverName(): string
            {
                return $this->driver;
            }
        };

        DB::shouldReceive('connection')->zeroOrMoreTimes()->andReturn($connection);
    }

    private function privateMethod(string $name): \ReflectionMethod
    {
        $method = (new ReflectionClass($this->filterApplier))->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }
}

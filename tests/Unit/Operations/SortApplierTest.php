<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Unit\Operations;

use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\SortApplier;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Sorter;

class SortApplierTest extends TestCase
{
    private SortApplier $sortApplier;

    private Sorter $sorter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sorter = $this->createMock(Sorter::class);
        $this->sortApplier = new SortApplier($this->sorter);
    }

    public function test_skips_sort_when_sort_field_is_null(): void
    {
        $query = $this->createMock(Builder::class);

        $this->sorter->expects($this->never())->method('apply');

        $this->sortApplier->apply($query, null, 1, ['name']);
    }

    public function test_skips_sort_when_sort_order_is_zero(): void
    {
        $query = $this->createMock(Builder::class);

        $this->sorter->expects($this->never())->method('apply');

        $this->sortApplier->apply($query, 'name', 0, ['name']);
    }

    public function test_legacy_mode_applies_any_sort_field(): void
    {
        $query = $this->createMock(Builder::class);

        $this->sorter
            ->expects($this->once())
            ->method('apply')
            ->with($query, 'display_name', 'asc');

        $this->sortApplier->apply($query, 'display_name', 1);
    }

    public function test_whitelist_ignores_unknown_sort_field(): void
    {
        $query = $this->createMock(Builder::class);

        $this->sorter->expects($this->never())->method('apply');

        $this->sortApplier->apply($query, 'display_name', 1, ['name', 'created_at']);
    }

    public function test_whitelist_resolves_alias_before_sorting(): void
    {
        $query = $this->createMock(Builder::class);

        $this->sorter
            ->expects($this->once())
            ->method('apply')
            ->with($query, 'name', 'desc');

        $this->sortApplier->apply(
            $query,
            'display_name',
            -1,
            ['display_name' => 'name', 'created_at']
        );
    }

    public function test_list_style_sortable_entries_are_whitelisted(): void
    {
        $query = $this->createMock(Builder::class);

        $this->sorter
            ->expects($this->once())
            ->method('apply')
            ->with($query, 'company.name', 'asc');

        $this->sortApplier->apply($query, 'company.name', 1, ['created_at', 'company.name']);
    }

    public function test_custom_sort_takes_precedence_over_alias(): void
    {
        $query = $this->createMock(Builder::class);
        $customApplied = false;

        $this->sorter->expects($this->never())->method('apply');

        $this->sortApplier->apply(
            $query,
            'display_name',
            1,
            ['display_name' => 'name'],
            [
                'display_name' => function (Builder $builder, string $direction) use ($query, &$customApplied): void {
                    $this->assertSame($query, $builder);
                    $this->assertSame('asc', $direction);
                    $customApplied = true;
                },
            ]
        );

        $this->assertTrue($customApplied);
    }

    public function test_direction_is_normalized_to_desc(): void
    {
        $query = $this->createMock(Builder::class);

        $this->sorter
            ->expects($this->once())
            ->method('apply')
            ->with($query, 'created_at', 'desc');

        $this->sortApplier->apply($query, 'created_at', -1, ['created_at']);
    }

    public function test_direction_is_normalized_to_asc_for_non_desc_orders(): void
    {
        $query = $this->createMock(Builder::class);

        $this->sorter
            ->expects($this->once())
            ->method('apply')
            ->with($query, 'created_at', 'asc');

        $this->sortApplier->apply($query, 'created_at', 99, ['created_at']);
    }

    public function test_sorter_normalizes_invalid_direction_strings(): void
    {
        $sorter = new Sorter;
        $reflectionClass = new \ReflectionClass($sorter);
        $method = $reflectionClass->getMethod('apply');
        $method->setAccessible(true);

        $query = $this->createMock(Builder::class);
        $query->expects($this->once())->method('orderBy')->with('name', 'asc');

        $method->invoke($sorter, $query, 'name', 'INVALID');
    }
}

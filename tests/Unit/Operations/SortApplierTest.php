<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Unit\Operations;

use Illuminate\Database\Eloquent\Builder;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\SortApplier;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Sorter;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Post;
use ZydLabs\LaravelDataTableKit\Tests\TestCase;

class SortApplierTest extends TestCase
{
    private SortApplier $sortApplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sortApplier = new SortApplier(new Sorter);
    }

    public function test_skips_sort_when_sort_field_is_null(): void
    {
        $query = Post::query();

        $this->sortApplier->apply($query, null, 1, ['title']);

        $this->assertSame([], $query->getQuery()->orders ?? []);
    }

    public function test_skips_sort_when_sort_order_is_zero(): void
    {
        $query = Post::query();

        $this->sortApplier->apply($query, 'title', 0, ['title']);

        $this->assertSame([], $query->getQuery()->orders ?? []);
    }

    public function test_legacy_mode_applies_any_sort_field(): void
    {
        $query = Post::query();

        $this->sortApplier->apply($query, 'title', 1);

        $this->assertSame([
            ['column' => 'title', 'direction' => 'asc'],
        ], $query->getQuery()->orders);
    }

    public function test_whitelist_ignores_unknown_sort_field(): void
    {
        $query = Post::query();

        $this->sortApplier->apply($query, 'display_name', 1, ['title', 'status']);

        $this->assertSame([], $query->getQuery()->orders ?? []);
    }

    public function test_whitelist_resolves_alias_before_sorting(): void
    {
        $query = Post::query();

        $this->sortApplier->apply(
            $query,
            'display_name',
            -1,
            ['display_name' => 'title', 'status']
        );

        $this->assertSame([
            ['column' => 'title', 'direction' => 'desc'],
        ], $query->getQuery()->orders);
    }

    public function test_list_style_sortable_entries_are_whitelisted(): void
    {
        $query = Post::query();

        $this->sortApplier->apply($query, 'status', 1, ['title', 'status']);

        $this->assertSame([
            ['column' => 'status', 'direction' => 'asc'],
        ], $query->getQuery()->orders);
    }

    public function test_custom_sort_takes_precedence_over_alias(): void
    {
        $query = Post::query();
        $customApplied = false;

        $this->sortApplier->apply(
            $query,
            'display_name',
            1,
            ['display_name' => 'title'],
            [
                'display_name' => function (Builder $builder, string $direction) use ($query, &$customApplied): void {
                    $this->assertSame($query, $builder);
                    $this->assertSame('asc', $direction);
                    $customApplied = true;
                    $builder->orderBy('notes', $direction);
                },
            ]
        );

        $this->assertTrue($customApplied);
        $this->assertSame([
            ['column' => 'notes', 'direction' => 'asc'],
        ], $query->getQuery()->orders);
    }

    public function test_direction_is_normalized_to_desc(): void
    {
        $query = Post::query();

        $this->sortApplier->apply($query, 'status', -1, ['status']);

        $this->assertSame([
            ['column' => 'status', 'direction' => 'desc'],
        ], $query->getQuery()->orders);
    }

    public function test_direction_is_normalized_to_asc_for_non_desc_orders(): void
    {
        $query = Post::query();

        $this->sortApplier->apply($query, 'status', 99, ['status']);

        $this->assertSame([
            ['column' => 'status', 'direction' => 'asc'],
        ], $query->getQuery()->orders);
    }

    public function test_sorter_normalizes_invalid_direction_strings(): void
    {
        $query = Post::query();

        (new Sorter)->apply($query, 'title', 'INVALID');

        $this->assertSame([
            ['column' => 'title', 'direction' => 'asc'],
        ], $query->getQuery()->orders);
    }
}

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

    public function test_belongs_to_sort_uses_relation_subquery(): void
    {
        $query = Post::query();

        (new Sorter)->apply($query, 'author.name', 'asc');

        $sql = strtolower(str_replace('"', '', $query->toSql()));
        $orders = $query->getQuery()->orders ?? [];

        $this->assertSame([], $query->getQuery()->joins ?? []);
        $this->assertStringContainsString('select', $sql);
        $this->assertStringContainsString('authors', $sql);
        $this->assertStringContainsString('author_id', $sql);
        $this->assertNotEmpty($orders);
        $this->assertSame('asc', $orders[0]['direction']);
    }

    public function test_two_belongs_to_same_table_use_distinct_foreign_keys_in_sort_subqueries(): void
    {
        $query = Post::query();
        $sorter = new Sorter;

        $sorter->apply($query, 'author.name', 'asc');
        $sorter->apply($query, 'editor.name', 'desc');

        $sql = strtolower(str_replace('"', '', $query->toSql()));
        $orders = $query->getQuery()->orders ?? [];

        $this->assertSame([], $query->getQuery()->joins ?? []);
        $this->assertStringContainsString('author_id', $sql);
        $this->assertStringContainsString('editor_id', $sql);
        $this->assertCount(2, $orders);
        $this->assertSame('asc', $orders[0]['direction']);
        $this->assertSame('desc', $orders[1]['direction']);
    }

    public function test_with_trashed_belongs_to_sort_subquery_omits_soft_delete_constraint(): void
    {
        $without = Post::query();
        (new Sorter)->apply($without, 'author.name', 'asc');

        $with = Post::query();
        (new Sorter)->apply($with, 'authorWithTrashed.name', 'desc');

        $withoutSql = strtolower(str_replace('"', '', $without->toSql()));
        $withSql = strtolower(str_replace('"', '', $with->toSql()));

        $this->assertStringContainsString('deleted_at is null', $withoutSql);
        $this->assertStringNotContainsString('deleted_at is null', $withSql);
    }
}

<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Feature;

use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\FilterApplier;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\GlobalSearchApplier;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Sorter;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Author;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Comment;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Company;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Employee;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Post;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Tag;
use ZydLabs\LaravelDataTableKit\Tests\TestCase;

final class SorterRelationScopeTest extends TestCase
{
    private Sorter $sorter;

    private FilterApplier $filterApplier;

    private GlobalSearchApplier $globalSearchApplier;

    private Author $alice;

    private Author $bob;

    private Author $inactive;

    private Post $postAlice;

    private Post $postBob;

    private Post $postInactive;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sorter = new Sorter;
        $this->filterApplier = new FilterApplier;
        $this->globalSearchApplier = new GlobalSearchApplier;
        $this->seedFixtures();
    }

    public function test_belongs_to_sort_preserves_relation_where(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->sorter->apply($query, 'activeAuthor.name', 'asc');

        $sql = strtolower(str_replace('"', '', $query->toSql()));
        $ids = $query->orderBy('posts.id')->pluck('posts.id')->map(fn ($id): int => (int) $id)->all();

        $this->assertStringContainsString('is_active', $sql);
        $this->assertContains((int) $this->postAlice->id, $ids);
        $this->assertContains((int) $this->postInactive->id, $ids);
        $this->assertSame([], $query->getQuery()->joins ?? []);
    }

    public function test_belongs_to_sort_preserves_author_tenant_scope(): void
    {
        Author::$tenantScopeId = null;
        $foreign = Author::query()->create([
            'tenant_id' => 2,
            'name' => 'Zed Foreign',
            'is_active' => true,
        ]);
        Author::$tenantScopeId = 1;

        $foreignPost = Post::query()->create([
            'author_id' => $foreign->id,
            'tenant_id' => 1,
            'title' => 'Foreign Author Sort Post',
            'views' => 1,
            'status' => 'published',
        ]);

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->sorter->apply($query, 'author.name', 'asc');

        $sql = strtolower(str_replace('"', '', $query->toSql()));

        $this->assertStringContainsString('tenant_id', $sql);
        $this->assertContains((int) $foreignPost->id, $query->pluck('posts.id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_filter_one_belongs_to_and_sort_another_without_join_collision(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->filterApplier->apply($query, [
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'Alice'],
                ],
            ],
        ], ['author.name']);
        $this->sorter->apply($query, 'editor.name', 'desc');

        $ids = $query->orderBy('posts.id')->pluck('posts.id')->map(fn ($id): int => (int) $id)->all();

        $this->assertSame([(int) $this->postAlice->id], $ids);
        $this->assertSame([], $query->getQuery()->joins ?? []);
    }

    public function test_global_search_plus_belongs_to_sort(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Alice', ['author.name', 'title']);
        $this->sorter->apply($query, 'editor.name', 'asc');

        $ids = $query->pluck('posts.id')->map(fn ($id): int => (int) $id)->all();

        $this->assertContains((int) $this->postAlice->id, $ids);
        $this->assertSame([], $query->getQuery()->joins ?? []);
    }

    public function test_has_many_sort_preserves_soft_deletes_and_tenant_scope(): void
    {
        $employee = Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 1,
            'name' => 'Sort Emp',
            'salary' => 50,
            'is_active' => true,
            'status' => 'active',
        ]);
        $employee->delete();

        Employee::$tenantScopeId = null;
        Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 2,
            'name' => 'Foreign Sort Emp',
            'salary' => 999,
            'is_active' => true,
            'status' => 'active',
        ]);
        Employee::$tenantScopeId = 1;

        $query = Company::query()->where('companies.tenant_id', 1);
        $this->sorter->apply($query, 'employees.name', 'asc');

        $sql = strtolower(str_replace('"', '', $query->toSql()));

        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringContainsString('tenant_id', $sql);
        $this->assertSame([], $query->getQuery()->joins ?? []);
    }

    public function test_belongs_to_many_sort_preserves_soft_deleted_tags(): void
    {
        $tag = Tag::query()->create(['name' => 'zzz-tag', 'tagged_on' => '2026-01-01']);
        $this->postAlice->tags()->attach($tag->id);
        $tag->delete();

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->sorter->apply($query, 'tags.name', 'asc');

        $sql = strtolower(str_replace('"', '', $query->toSql()));

        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertSame([], $query->getQuery()->joins ?? []);
    }

    public function test_morph_many_sort_applies_morph_type_and_soft_deletes(): void
    {
        Comment::query()->create([
            'body' => 'Sort comment',
            'commentable_type' => Post::class,
            'commentable_id' => $this->postAlice->id,
        ]);

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->sorter->apply($query, 'comments.body', 'desc');

        $sql = strtolower(str_replace('"', '', $query->toSql()));

        $this->assertStringContainsString('commentable_type', $sql);
        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertCount(3, $query->pluck('posts.id'));
    }

    public function test_sort_plus_pagination_total_remains_stable(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->sorter->apply($query, 'author.name', 'asc');

        $total = (clone $query)->count();
        $page = (clone $query)->limit(10)->get();

        $this->assertSame(3, $total);
        $this->assertCount(3, $page);
        $this->assertSame([], $query->getQuery()->joins ?? []);
    }

    public function test_nullable_belongs_to_sort_does_not_error(): void
    {
        Post::query()->create([
            'author_id' => null,
            'editor_id' => null,
            'tenant_id' => 1,
            'title' => 'Orphan Sort',
            'views' => 0,
            'status' => 'draft',
        ]);

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->sorter->apply($query, 'author.name', 'asc');

        $this->assertGreaterThanOrEqual(4, $query->count());
    }

    private function seedFixtures(): void
    {
        $this->alice = Author::query()->create([
            'tenant_id' => 1,
            'name' => 'Alice',
            'is_active' => true,
        ]);
        $this->bob = Author::query()->create([
            'tenant_id' => 1,
            'name' => 'Bob',
            'is_active' => true,
        ]);
        $this->inactive = Author::query()->create([
            'tenant_id' => 1,
            'name' => 'Inactive',
            'is_active' => false,
        ]);

        $this->postAlice = Post::query()->create([
            'author_id' => $this->alice->id,
            'editor_id' => $this->bob->id,
            'tenant_id' => 1,
            'title' => 'Alice Post',
            'views' => 10,
            'status' => 'published',
        ]);
        $this->postBob = Post::query()->create([
            'author_id' => $this->bob->id,
            'editor_id' => $this->alice->id,
            'tenant_id' => 1,
            'title' => 'Bob Post',
            'views' => 20,
            'status' => 'published',
        ]);
        $this->postInactive = Post::query()->create([
            'author_id' => $this->inactive->id,
            'editor_id' => $this->alice->id,
            'tenant_id' => 1,
            'title' => 'Inactive Author Post',
            'views' => 5,
            'status' => 'draft',
        ]);

        $this->company = Company::query()->create(['tenant_id' => 1, 'name' => 'Sort Co']);
    }
}

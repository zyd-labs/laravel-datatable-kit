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
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\HealthDocument;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Post;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Tag;
use ZydLabs\LaravelDataTableKit\Tests\TestCase;

final class GlobalSearchRelationScopeTest extends TestCase
{
    private GlobalSearchApplier $globalSearchApplier;

    private FilterApplier $filterApplier;

    private Sorter $sorter;

    private Author $authorAlice;

    private Author $authorBob;

    private Author $trashedCarol;

    private Post $postAliceBob;

    private Post $postBobAlice;

    private Post $postTrashedEditor;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->globalSearchApplier = new GlobalSearchApplier;
        $this->filterApplier = new FilterApplier;
        $this->sorter = new Sorter;
        $this->seedFixtures();
    }

    public function test_two_belongs_to_same_table_can_be_filtered_together(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->filterApplier->apply($query, [
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'Alice'],
                ],
            ],
            'editor.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'Bob'],
                ],
            ],
        ], ['author.name', 'editor.name']);

        $ids = $this->postIds($query);

        $this->assertSame([(int) $this->postAliceBob->id], $ids);
        $this->assertJoinCount($query, 2);
    }

    public function test_filter_one_belongs_to_and_sort_the_other(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->filterApplier->apply($query, [
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Alice'],
                ],
            ],
        ], ['author.name']);
        $this->sorter->apply($query, 'editor.name', 'asc');

        $ids = $this->postIds($query);

        $this->assertSame([(int) $this->postAliceBob->id], $ids);
        $this->assertStringContainsString('editor', $this->normalizeSql($query->toSql()));
    }

    public function test_global_search_uses_distinct_aliases_for_two_belongs_to_same_table(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Alice', ['author.name', 'editor.name']);

        $sql = $this->normalizeSql($query->toSql());
        $ids = $this->postIds($query);

        $this->assertStringContainsString('as author', $sql);
        $this->assertStringContainsString('as editor', $sql);
        $this->assertJoinCount($query, 2);
        // Alice is author on postAliceBob, editor on postBobAlice and postTrashedEditor.
        $this->assertSame(
            [
                (int) $this->postAliceBob->id,
                (int) $this->postBobAlice->id,
                (int) $this->postTrashedEditor->id,
            ],
            $ids
        );
    }

    public function test_global_search_with_trashed_belongs_to_includes_soft_deleted_related(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Carol', ['authorWithTrashed.name']);

        $ids = $this->postIds($query);

        $this->assertSame([(int) $this->postTrashedEditor->id], $ids);
        $this->assertStringContainsString('__with', $this->normalizeSql($query->toSql()));
    }

    public function test_global_search_only_trashed_belongs_to_matches_deleted_related_only(): void
    {
        $matching = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($matching, 'Carol', ['authorOnlyTrashed.name']);

        $alive = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($alive, 'Alice', ['authorOnlyTrashed.name']);

        $this->assertSame([(int) $this->postTrashedEditor->id], $this->postIds($matching));
        $this->assertSame([], $this->postIds($alive));
        $this->assertStringContainsString('__only', $this->normalizeSql($matching->toSql()));
    }

    public function test_normal_and_with_trashed_belongs_to_can_be_searched_together(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Carol', [
            'author.name',
            'authorWithTrashed.name',
        ]);

        $ids = $this->postIds($query);

        $this->assertSame([(int) $this->postTrashedEditor->id], $ids);
        $this->assertJoinCount($query, 2);
    }

    public function test_self_referencing_belongs_to_uses_relation_alias(): void
    {
        $manager = Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 1,
            'name' => 'Manager Ada',
            'salary' => 200,
            'is_active' => true,
            'status' => 'active',
        ]);
        $report = Employee::query()->create([
            'company_id' => $this->company->id,
            'manager_id' => $manager->id,
            'tenant_id' => 1,
            'name' => 'Report Eve',
            'salary' => 80,
            'is_active' => true,
            'status' => 'active',
        ]);

        $query = Employee::query()->where('employees.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Ada', ['manager.name']);

        $sql = $this->normalizeSql($query->toSql());
        $ids = $query->orderBy('employees.id')->pluck('employees.id')->map(fn ($id): int => (int) $id)->all();

        $this->assertStringContainsString('as manager', $sql);
        $this->assertSame([(int) $report->id], $ids);
    }

    public function test_has_many_global_search_preserves_tenant_scope(): void
    {
        Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 2,
            'name' => 'Foreign Search Hit',
            'salary' => 10,
            'is_active' => true,
            'status' => 'active',
        ]);

        Employee::$tenantScopeId = 1;

        $query = Company::query()->where('companies.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Foreign Search Hit', ['employees.name']);

        $this->assertSame([], $this->companyIds($query));
    }

    public function test_has_many_global_search_preserves_relation_where(): void
    {
        Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 1,
            'name' => 'Inactive Unique',
            'salary' => 10,
            'is_active' => false,
            'status' => 'paused',
        ]);

        $query = Company::query()->where('companies.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Inactive Unique', ['activeEmployees.name']);

        $this->assertSame([], $this->companyIds($query));
    }

    public function test_has_many_global_search_excludes_soft_deleted_related(): void
    {
        $employee = Employee::query()->where('name', 'Searchable Emp')->firstOrFail();
        $employee->delete();

        $query = Company::query()->where('companies.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Searchable Emp', ['employees.name']);

        $this->assertSame([], $this->companyIds($query));
    }

    public function test_has_one_style_search_uses_where_has_not_raw_exists_bypass(): void
    {
        $query = Company::query()->where('companies.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Searchable Emp', ['employees.name']);

        $sql = $this->normalizeSql($query->toSql());

        $this->assertStringContainsString('exists', $sql);
        $this->assertStringContainsString('tenant_id', $sql);
        $this->assertSame([(int) $this->company->id], $this->companyIds($query));
    }

    public function test_nested_relation_global_search_preserves_scopes(): void
    {
        Employee::$tenantScopeId = null;
        $foreignEmployee = Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 2,
            'name' => 'Nested Foreign',
            'salary' => 10,
            'is_active' => true,
            'status' => 'active',
        ]);

        HealthDocument::query()->create([
            'employee_id' => $foreignEmployee->id,
            'label' => 'SECRET-DOC',
            'score' => 1,
        ]);

        Employee::$tenantScopeId = 1;

        $query = Company::query()->where('companies.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'SECRET-DOC', ['employees.healthDocuments.label']);

        $this->assertSame([], $this->companyIds($query));

        $visible = Company::query()->where('companies.tenant_id', 1);
        $this->globalSearchApplier->apply($visible, 'VISIBLE-DOC', ['employees.healthDocuments.label']);

        $this->assertSame([(int) $this->company->id], $this->companyIds($visible));
    }

    public function test_belongs_to_many_global_search_excludes_soft_deleted_tags(): void
    {
        $tag = Tag::query()->where('name', 'search-tag')->firstOrFail();
        $tag->delete();

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'search-tag', ['tags.name']);

        $this->assertSame([], $this->postIds($query));
    }

    public function test_morph_many_global_search_excludes_soft_deleted_comments(): void
    {
        Comment::query()->create([
            'body' => 'Live comment',
            'commentable_type' => Post::class,
            'commentable_id' => $this->postAliceBob->id,
        ]);
        $deleted = Comment::query()->create([
            'body' => 'Deleted comment',
            'commentable_type' => Post::class,
            'commentable_id' => $this->postAliceBob->id,
        ]);
        $deleted->delete();

        $visible = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($visible, 'Live comment', ['comments.body']);

        $hidden = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($hidden, 'Deleted comment', ['comments.body']);

        $this->assertSame([(int) $this->postAliceBob->id], $this->postIds($visible));
        $this->assertSame([], $this->postIds($hidden));
    }

    public function test_direct_column_global_search(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Alice-Bob', ['title']);

        $this->assertSame([(int) $this->postAliceBob->id], $this->postIds($query));
    }

    public function test_mixed_relation_and_direct_fields_or_inside_tenant_scope(): void
    {
        $foreign = Post::query()->create([
            'author_id' => $this->authorAlice->id,
            'editor_id' => $this->authorBob->id,
            'tenant_id' => 2,
            'title' => 'Alice-Bob Foreign',
            'views' => 1,
            'status' => 'published',
        ]);

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Alice-Bob', [
            'title',
            'author.name',
            'editor.name',
        ]);

        $ids = $this->postIds($query);

        $this->assertContains((int) $this->postAliceBob->id, $ids);
        $this->assertNotContains((int) $foreign->id, $ids);
    }

    public function test_nullable_belongs_to_global_search_does_not_error(): void
    {
        $orphan = Post::query()->create([
            'author_id' => null,
            'editor_id' => null,
            'tenant_id' => 1,
            'title' => 'Orphan',
            'views' => 0,
            'status' => 'draft',
        ]);

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Nobody', ['author.name', 'editor.name']);

        $ids = $this->postIds($query);

        $this->assertSame([], $ids);
        $this->assertDatabaseHas('posts', ['id' => $orphan->id]);
    }

    public function test_invalid_relation_name_is_ignored(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'ZZZ-NO-MATCH', ['missingRel.name', 'title']);

        $this->assertSame([], $query->getQuery()->joins ?? []);
        $this->assertSame([], $this->postIds($query));
    }

    public function test_same_join_is_not_duplicated(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Alice', ['author.name']);
        $this->filterApplier->apply($query, [
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Ali'],
                ],
            ],
        ], ['author.name']);

        $this->assertJoinCount($query, 1);
        $this->assertNotEmpty($this->postIds($query));
    }

    public function test_global_search_plus_filter_plus_sort_and_pagination(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Alice', ['author.name', 'editor.name', 'title']);
        $this->filterApplier->apply($query, [
            'status' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'published'],
                ],
            ],
        ], ['status']);
        $this->sorter->apply($query, 'editor.name', 'desc');

        $ids = $this->postIds(clone $query);
        $page = (clone $query)->select('posts.*')->limit(10)->get();

        $this->assertCount(2, $ids);
        $this->assertCount(2, $page);
        $this->assertTrue($page->every(fn (Post $post): bool => (int) $post->tenant_id === 1));
    }

    public function test_missing_related_row_does_not_match_or_error(): void
    {
        Post::query()->create([
            'author_id' => 9999,
            'editor_id' => null,
            'tenant_id' => 1,
            'title' => 'Broken FK',
            'views' => 0,
            'status' => 'draft',
        ]);

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->globalSearchApplier->apply($query, 'Ghost', ['author.name']);

        $this->assertSame([], $this->postIds($query));
    }

    /**
     * @return list<int>
     */
    private function postIds($query): array
    {
        return $query->orderBy('posts.id')->pluck('posts.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    private function companyIds($query): array
    {
        return $query->orderBy('companies.id')->pluck('companies.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function assertJoinCount($query, int $expected): void
    {
        $this->assertCount($expected, $query->getQuery()->joins ?? []);
    }

    private function normalizeSql(string $sql): string
    {
        return strtolower(str_replace('"', '', $sql));
    }

    private function seedFixtures(): void
    {
        $this->authorAlice = Author::query()->create(['name' => 'Alice']);
        $this->authorBob = Author::query()->create(['name' => 'Bob']);
        $this->trashedCarol = Author::query()->create(['name' => 'Carol']);
        $this->trashedCarol->delete();

        $this->postAliceBob = Post::query()->create([
            'author_id' => $this->authorAlice->id,
            'editor_id' => $this->authorBob->id,
            'tenant_id' => 1,
            'title' => 'Alice-Bob Post',
            'views' => 10,
            'status' => 'published',
        ]);

        $this->postBobAlice = Post::query()->create([
            'author_id' => $this->authorBob->id,
            'editor_id' => $this->authorAlice->id,
            'tenant_id' => 1,
            'title' => 'Bob-Alice Post',
            'views' => 20,
            'status' => 'published',
        ]);

        $this->postTrashedEditor = Post::query()->create([
            'author_id' => $this->trashedCarol->id,
            'editor_id' => $this->authorAlice->id,
            'tenant_id' => 1,
            'title' => 'Trashed Author Post',
            'views' => 5,
            'status' => 'draft',
        ]);

        $tag = Tag::query()->create(['name' => 'search-tag', 'tagged_on' => '2026-03-01']);
        $this->postAliceBob->tags()->attach($tag->id);

        $this->company = Company::query()->create(['tenant_id' => 1, 'name' => 'Search Co']);
        $employee = Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 1,
            'name' => 'Searchable Emp',
            'salary' => 100,
            'is_active' => true,
            'status' => 'active',
        ]);

        HealthDocument::query()->create([
            'employee_id' => $employee->id,
            'label' => 'VISIBLE-DOC',
            'score' => 50,
        ]);
    }
}

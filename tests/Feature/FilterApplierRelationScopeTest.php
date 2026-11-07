<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Feature;

use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\FilterApplier;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Author;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Comment;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Company;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Employee;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\LegacyAuthor;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Post;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Tag;
use ZydLabs\LaravelDataTableKit\Tests\TestCase;

final class FilterApplierRelationScopeTest extends TestCase
{
    private FilterApplier $filterApplier;

    private Author $aliveAuthor;

    private Author $trashedAuthor;

    private Post $aliveAuthorPost;

    private Post $trashedAuthorPost;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filterApplier = new FilterApplier;
        $this->seedRelationFixtures();
    }

    public function test_belongs_to_excludes_soft_deleted_related_rows_by_default(): void
    {
        $ids = $this->filterPostIds([
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Trashed'],
                ],
            ],
        ], ['author.name']);

        $this->assertSame([], $ids);
    }

    public function test_belongs_to_with_trashed_includes_soft_deleted_related_rows(): void
    {
        $ids = $this->filterPostIds([
            'authorWithTrashed.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Trashed'],
                ],
            ],
        ], ['authorWithTrashed.name']);

        $this->assertSame([(int) $this->trashedAuthorPost->id], $ids);
    }

    public function test_belongs_to_only_trashed_matches_soft_deleted_related_rows_only(): void
    {
        $trashedIds = $this->filterPostIds([
            'authorOnlyTrashed.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Trashed'],
                ],
            ],
        ], ['authorOnlyTrashed.name']);

        $aliveIds = $this->filterPostIds([
            'authorOnlyTrashed.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Alive'],
                ],
            ],
        ], ['authorOnlyTrashed.name']);

        $this->assertSame([(int) $this->trashedAuthorPost->id], $trashedIds);
        $this->assertSame([], $aliveIds);
    }

    public function test_custom_deleted_at_column_is_respected_on_belongs_to_join(): void
    {
        $legacy = LegacyAuthor::query()->create(['name' => 'Legacy Alive']);
        $removed = LegacyAuthor::query()->create(['name' => 'Legacy Gone']);
        $removed->delete();

        $visible = Post::query()->create([
            'author_id' => null,
            'legacy_author_id' => $legacy->id,
            'tenant_id' => 1,
            'title' => 'Legacy Visible',
            'views' => 1,
            'status' => 'published',
        ]);
        Post::query()->create([
            'author_id' => null,
            'legacy_author_id' => $removed->id,
            'tenant_id' => 1,
            'title' => 'Legacy Hidden',
            'views' => 1,
            'status' => 'published',
        ]);

        $ids = $this->filterPostIds([
            'legacyAuthor.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Legacy'],
                ],
            ],
        ], ['legacyAuthor.name']);

        $this->assertSame([(int) $visible->id], $ids);
    }

    public function test_same_related_table_can_be_joined_under_different_trashed_modes(): void
    {
        $ids = $this->filterPostIds([
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Alive'],
                ],
            ],
            'authorWithTrashed.name' => [
                'operator' => 'or',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Trashed'],
                ],
            ],
        ], ['author.name', 'authorWithTrashed.name']);

        // AND between filter groups: author.name Alive AND (authorWithTrashed Trashed)
        // No post satisfies both groups simultaneously.
        $this->assertSame([], $ids);

        $orScoped = Post::query()->where('posts.tenant_id', 1);
        $this->filterApplier->apply($orScoped, [
            'authorWithTrashed.name' => [
                'operator' => 'or',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Alive'],
                    ['matchMode' => 'contains', 'value' => 'Trashed'],
                ],
            ],
        ], ['authorWithTrashed.name']);

        $this->assertSame(
            [(int) $this->aliveAuthorPost->id, (int) $this->trashedAuthorPost->id],
            $orScoped->orderBy('posts.id')->pluck('posts.id')->map(fn ($id): int => (int) $id)->all()
        );
    }

    public function test_has_many_relation_where_excludes_inactive_employees(): void
    {
        $ids = $this->filterCompanyIds([
            'activeEmployees.salary' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'gte', 'value' => 100],
                ],
            ],
        ], ['activeEmployees.salary']);

        $this->assertSame([(int) $this->company->id], $ids);

        // Inactive high salary must not satisfy activeEmployees filter.
        Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 1,
            'name' => 'Inactive Rich',
            'salary' => 9999,
            'is_active' => false,
            'status' => 'paused',
        ]);

        $again = $this->filterCompanyIds([
            'activeEmployees.salary' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 9999],
                ],
            ],
        ], ['activeEmployees.salary']);

        $this->assertSame([], $again);
    }

    public function test_has_many_global_tenant_scope_is_preserved(): void
    {
        Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 2,
            'name' => 'Other Tenant Emp',
            'salary' => 777,
            'is_active' => true,
            'status' => 'active',
        ]);

        Employee::$tenantScopeId = 1;

        $ids = $this->filterCompanyIds([
            'employees.salary' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 777],
                ],
            ],
        ], ['employees.salary']);

        $this->assertSame([], $ids);
    }

    public function test_has_many_soft_deleted_related_rows_are_excluded(): void
    {
        $employee = Employee::query()->where('name', 'Active Emp')->firstOrFail();
        $employee->delete();

        $ids = $this->filterCompanyIds([
            'employees.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'Active Emp'],
                ],
            ],
        ], ['employees.name']);

        $this->assertSame([], $ids);
    }

    public function test_belongs_to_many_excludes_soft_deleted_tags(): void
    {
        $tag = Tag::query()->where('name', 'news')->firstOrFail();
        $tag->delete();

        $ids = $this->filterPostIds([
            'tags.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'news'],
                ],
            ],
        ], ['tags.name']);

        $this->assertSame([], $ids);
    }

    public function test_nested_relation_preserves_employee_scopes(): void
    {
        Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 2,
            'name' => 'Nested Other Tenant',
            'salary' => 10,
            'is_active' => true,
            'status' => 'active',
        ]);

        // healthDocuments under employees — tenant scope on employees still applies via whereHas chain.
        $ids = $this->filterCompanyIds([
            'employees.healthDocuments.label' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'A+'],
                ],
            ],
        ], ['employees.healthDocuments.label']);

        $this->assertSame([(int) $this->company->id], $ids);
    }

    public function test_morph_many_excludes_soft_deleted_comments(): void
    {
        Comment::query()->create([
            'body' => 'Visible',
            'commentable_type' => Post::class,
            'commentable_id' => $this->aliveAuthorPost->id,
        ]);
        $deleted = Comment::query()->create([
            'body' => 'Gone',
            'commentable_type' => Post::class,
            'commentable_id' => $this->aliveAuthorPost->id,
        ]);
        $deleted->delete();

        $visible = $this->filterPostIds([
            'comments.body' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'Visible'],
                ],
            ],
        ], ['comments.body']);

        $hidden = $this->filterPostIds([
            'comments.body' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'Gone'],
                ],
            ],
        ], ['comments.body']);

        $this->assertSame([(int) $this->aliveAuthorPost->id], $visible);
        $this->assertSame([], $hidden);
    }

    public function test_has_many_count_only_counts_visible_related_rows(): void
    {
        Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 1,
            'name' => 'Inactive Counted?',
            'salary' => 1,
            'is_active' => false,
            'status' => 'paused',
        ]);

        $activeCount = $this->filterCompanyIds([
            'activeEmployees_count' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 1],
                ],
            ],
        ], ['activeEmployees_count']);

        $this->assertSame([(int) $this->company->id], $activeCount);

        $tooHigh = $this->filterCompanyIds([
            'activeEmployees_count' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'gte', 'value' => 2],
                ],
            ],
        ], ['activeEmployees_count']);

        $this->assertSame([], $tooHigh);
    }

    public function test_belongs_to_many_count_ignores_soft_deleted_tags(): void
    {
        $tag = Tag::query()->where('name', 'news')->firstOrFail();
        $tag->delete();

        $ids = $this->filterPostIds([
            'tags_count' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'gte', 'value' => 1],
                ],
            ],
        ], ['tags_count']);

        $this->assertSame([], $ids);
    }

    public function test_has_many_between_still_works_via_where_has(): void
    {
        $ids = $this->filterCompanyIds([
            'employees.salary' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'between', 'value' => [90, 110]],
                ],
            ],
        ], ['employees.salary']);

        $this->assertSame([(int) $this->company->id], $ids);
    }

    public function test_belongs_to_in_and_relation_is_null_regressions(): void
    {
        $inIds = $this->filterPostIds([
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'in', 'value' => ['Alive Author']],
                ],
            ],
        ], ['author.name']);

        $nullAuthor = Post::query()->create([
            'author_id' => null,
            'tenant_id' => 1,
            'title' => 'No Author',
            'views' => null,
            'status' => 'draft',
        ]);

        $nullIds = $this->filterPostIds([
            'author_id' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'isNull', 'value' => null],
                ],
            ],
        ], ['author_id']);

        $this->assertSame([(int) $this->aliveAuthorPost->id], $inIds);
        $this->assertContains((int) $nullAuthor->id, $nullIds);
    }

    public function test_relation_or_constraints_stay_inside_tenant_scope(): void
    {
        $foreign = Post::query()->create([
            'author_id' => $this->aliveAuthor->id,
            'tenant_id' => 2,
            'title' => 'Foreign',
            'views' => 1000,
            'status' => 'published',
            'published_on' => '2026-01-15',
        ]);

        $query = Post::query()->where('posts.tenant_id', 1);
        $this->filterApplier->apply($query, [
            'views' => [
                'operator' => 'or',
                'constraints' => [
                    ['matchMode' => 'between', 'value' => [90, 110]],
                    ['matchMode' => 'between', 'value' => [900, 1100]],
                ],
            ],
        ], ['views']);

        $ids = $query->orderBy('posts.id')->pluck('posts.id')->map(fn ($id): int => (int) $id)->all();

        $this->assertContains((int) $this->aliveAuthorPost->id, $ids);
        $this->assertNotContains((int) $foreign->id, $ids);
    }

    public function test_filtered_pagination_total_remains_consistent(): void
    {
        $query = Company::query();
        $this->filterApplier->apply($query, [
            'activeEmployees.salary' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'gte', 'value' => 50],
                ],
            ],
        ], ['activeEmployees.salary']);

        $total = (clone $query)->count();
        $ids = $query->orderBy('companies.id')->pluck('companies.id')->all();

        $this->assertSame(1, $total);
        $this->assertSame([(int) $this->company->id], array_map('intval', $ids));
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @param  list<string>  $filterable
     * @return list<int>
     */
    private function filterPostIds(array $filters, array $filterable): array
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->filterApplier->apply($query, $filters, $filterable);

        return $query->orderBy('posts.id')->pluck('posts.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @param  list<string>  $filterable
     * @return list<int>
     */
    private function filterCompanyIds(array $filters, array $filterable): array
    {
        $query = Company::query()->where('companies.tenant_id', 1);
        $this->filterApplier->apply($query, $filters, $filterable);

        return $query->orderBy('companies.id')->pluck('companies.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function seedRelationFixtures(): void
    {
        $this->aliveAuthor = Author::query()->create(['name' => 'Alive Author']);
        $this->trashedAuthor = Author::query()->create(['name' => 'Trashed Author']);
        $this->trashedAuthor->delete();

        $this->aliveAuthorPost = Post::query()->create([
            'author_id' => $this->aliveAuthor->id,
            'tenant_id' => 1,
            'title' => 'Alive Post',
            'views' => 100,
            'status' => 'published',
            'published_on' => '2026-01-15',
            'notes' => 'pinned',
        ]);

        $this->trashedAuthorPost = Post::query()->create([
            'author_id' => $this->trashedAuthor->id,
            'tenant_id' => 1,
            'title' => 'Trashed Author Post',
            'views' => 50,
            'status' => 'draft',
            'published_on' => '2026-02-01',
            'notes' => null,
        ]);

        $tag = Tag::query()->create(['name' => 'news', 'tagged_on' => '2026-03-01']);
        $this->aliveAuthorPost->tags()->attach($tag->id);

        $this->company = Company::query()->create(['tenant_id' => 1, 'name' => 'Acme']);
        $employee = Employee::query()->create([
            'company_id' => $this->company->id,
            'tenant_id' => 1,
            'name' => 'Active Emp',
            'salary' => 100,
            'is_active' => true,
            'status' => 'active',
            'hired_on' => '2025-01-01',
        ]);

        \ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\HealthDocument::query()->create([
            'employee_id' => $employee->id,
            'label' => 'A+',
            'score' => 90,
        ]);
    }
}

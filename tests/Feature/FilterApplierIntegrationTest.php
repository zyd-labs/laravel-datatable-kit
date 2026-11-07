<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Feature;

use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\FilterApplier;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Author;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Company;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Employee;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\HealthDocument;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Post;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Tag;
use ZydLabs\LaravelDataTableKit\Tests\TestCase;

final class FilterApplierIntegrationTest extends TestCase
{
    private FilterApplier $filterApplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filterApplier = new FilterApplier;
        $this->seedFixtures();
    }

    public function test_is_null_returns_rows_with_null_column(): void
    {
        $ids = $this->filterPostIds([
            'notes' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'isNull', 'value' => null],
                ],
            ],
        ], ['notes']);

        $this->assertSame([2, 3, 4], $ids);
    }

    public function test_is_not_null_returns_rows_with_non_null_column(): void
    {
        $ids = $this->filterPostIds([
            'notes' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'isNotNull', 'value' => null],
                ],
            ],
        ], ['notes']);

        $this->assertSame([1], $ids);
    }

    public function test_equals_null_behaves_like_is_null(): void
    {
        $ids = $this->filterPostIds([
            'notes' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => null],
                ],
            ],
        ], ['notes']);

        $this->assertSame([2, 3, 4], $ids);
    }

    public function test_not_equals_null_behaves_like_is_not_null(): void
    {
        $ids = $this->filterPostIds([
            'notes' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'notEquals', 'value' => null],
                ],
            ],
        ], ['notes']);

        $this->assertSame([1], $ids);
    }

    public function test_zero_and_false_are_not_treated_as_null(): void
    {
        $zeroIds = $this->filterPostIds([
            'views' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 0],
                ],
            ],
        ], ['views']);

        $this->assertSame([3], $zeroIds);

        $nullIds = $this->filterPostIds([
            'views' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'isNull', 'value' => null],
                ],
            ],
        ], ['views']);

        $this->assertSame([4], $nullIds);
    }

    public function test_empty_string_constraint_is_not_skipped_for_equals(): void
    {
        Post::query()->whereKey(2)->update(['notes' => '']);

        $ids = $this->filterPostIds([
            'notes' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => ''],
                ],
            ],
        ], ['notes']);

        $this->assertSame([2], $ids);
    }

    public function test_belongs_to_contains_filter(): void
    {
        $ids = $this->filterPostIds([
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Ali'],
                ],
            ],
        ], ['author.name']);

        $this->assertSame([1], $ids);
    }

    public function test_belongs_to_in_filter(): void
    {
        $ids = $this->filterPostIds([
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'in', 'value' => ['Ali Yilmaz', 'Missing Author']],
                ],
            ],
        ], ['author.name']);

        $this->assertSame([1], $ids);
    }

    public function test_direct_column_in_filter(): void
    {
        $ids = $this->filterPostIds([
            'status' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'in', 'value' => ['draft', 'archived']],
                ],
            ],
        ], ['status']);

        $this->assertSame([2, 3, 4], $ids);
    }

    public function test_has_many_between_filter(): void
    {
        $ids = $this->filterAuthorIds([
            'posts.views' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'between', 'value' => [50, 150]],
                ],
            ],
        ], ['posts.views']);

        $this->assertSame([1], $ids);
    }

    public function test_belongs_to_many_date_before_filter(): void
    {
        $ids = $this->filterPostIds([
            'tags.tagged_on' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'dateBefore', 'value' => '2026-06-01'],
                ],
            ],
        ], ['tags.tagged_on']);

        $this->assertSame([1], $ids);
    }

    public function test_nested_relation_equals_filter(): void
    {
        $ids = $this->filterCompanyIds([
            'employees.healthDocuments.label' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'A+'],
                ],
            ],
        ], ['employees.healthDocuments.label']);

        $this->assertSame([1], $ids);
    }

    public function test_nullable_belongs_to_is_null_filter(): void
    {
        $ids = $this->filterPostIds([
            'author_id' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'isNull', 'value' => null],
                ],
            ],
        ], ['author_id']);

        $this->assertSame([4], $ids);
    }

    public function test_multiple_relation_constraints_and(): void
    {
        $ids = $this->filterPostIds([
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'a'],
                ],
            ],
            'status' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'published'],
                ],
            ],
        ], ['author.name', 'status']);

        $this->assertSame([1], $ids);
    }

    public function test_soft_deleted_authors_are_excluded_from_belongs_to_join_by_default(): void
    {
        Author::query()->whereKey(2)->delete();

        $ids = $this->filterPostIds([
            'author.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'contains', 'value' => 'Veli'],
                ],
            ],
        ], ['author.name']);

        $this->assertSame([], $ids);
    }

    public function test_two_between_constraints_with_or(): void
    {
        $ids = $this->filterPostIds([
            'views' => [
                'operator' => 'or',
                'constraints' => [
                    ['matchMode' => 'between', 'value' => [90, 110]],
                    ['matchMode' => 'between', 'value' => [190, 210]],
                ],
            ],
        ], ['views']);

        $this->assertSame([1, 2], $ids);
    }

    public function test_two_in_constraints_with_or(): void
    {
        $ids = $this->filterPostIds([
            'status' => [
                'operator' => 'or',
                'constraints' => [
                    ['matchMode' => 'in', 'value' => ['published']],
                    ['matchMode' => 'in', 'value' => ['archived']],
                ],
            ],
        ], ['status']);

        $this->assertSame([1, 3], $ids);
    }

    public function test_is_null_or_equals_combination(): void
    {
        $ids = $this->filterPostIds([
            'notes' => [
                'operator' => 'or',
                'constraints' => [
                    ['matchMode' => 'isNull', 'value' => null],
                    ['matchMode' => 'equals', 'value' => 'pinned'],
                ],
            ],
        ], ['notes']);

        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function test_date_filters_with_or(): void
    {
        $ids = $this->filterPostIds([
            'published_on' => [
                'operator' => 'or',
                'constraints' => [
                    ['matchMode' => 'dateIs', 'value' => '2026-01-15'],
                    ['matchMode' => 'dateAfter', 'value' => '2026-08-01'],
                ],
            ],
        ], ['published_on']);

        $this->assertSame([1, 3], $ids);
    }

    public function test_and_constraints_require_all_matches(): void
    {
        $ids = $this->filterPostIds([
            'status' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'published'],
                    ['matchMode' => 'notEquals', 'value' => 'draft'],
                ],
            ],
        ], ['status']);

        $this->assertSame([1], $ids);
    }

    public function test_tenant_scope_is_preserved_with_or_filters(): void
    {
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

        $ids = $query->orderBy('posts.id')->pluck('posts.id')->all();

        // Post #5 belongs to tenant 2 with views=1000 and must stay excluded.
        $this->assertSame([1], $ids);
    }

    public function test_non_whitelisted_filter_is_ignored(): void
    {
        $ids = $this->filterPostIds([
            'title' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'Secret'],
                ],
            ],
        ], ['status']);

        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function test_invalid_relation_name_does_not_throw(): void
    {
        $ids = $this->filterPostIds([
            'missing.name' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => 'x'],
                ],
            ],
        ], ['missing.name']);

        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function test_invalid_between_payload_is_ignored(): void
    {
        $ids = $this->filterPostIds([
            'views' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'between', 'value' => [100]],
                ],
            ],
        ], ['views']);

        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function test_empty_in_array_matches_no_rows(): void
    {
        $ids = $this->filterPostIds([
            'status' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'in', 'value' => []],
                ],
            ],
        ], ['status']);

        $this->assertSame([], $ids);
    }

    public function test_bindings_prevent_sql_injection_payloads(): void
    {
        $ids = $this->filterPostIds([
            'title' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'equals', 'value' => "published'; DROP TABLE posts; --"],
                ],
            ],
        ], ['title']);

        $this->assertSame([], $ids);
        $this->assertSame(5, Post::query()->count());
    }

    public function test_pagination_total_matches_filtered_ids(): void
    {
        $query = Post::query()->where('posts.tenant_id', 1);
        $this->filterApplier->apply($query, [
            'status' => [
                'operator' => 'and',
                'constraints' => [
                    ['matchMode' => 'in', 'value' => ['draft', 'archived']],
                ],
            ],
        ], ['status']);

        $total = (clone $query)->count();
        $ids = $query->orderBy('posts.id')->pluck('posts.id')->map(fn ($id): int => (int) $id)->all();

        $this->assertSame(3, $total);
        $this->assertSame([2, 3, 4], $ids);
    }

    /**
     * Default helper scopes to tenant 1 so the cross-tenant fixture (#5) only
     * participates in explicit tenant-isolation assertions.
     *
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
    private function filterAuthorIds(array $filters, array $filterable): array
    {
        $query = Author::query();
        $this->filterApplier->apply($query, $filters, $filterable);

        return $query->orderBy('authors.id')->pluck('authors.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @param  list<string>  $filterable
     * @return list<int>
     */
    private function filterCompanyIds(array $filters, array $filterable): array
    {
        $query = Company::query();
        $this->filterApplier->apply($query, $filters, $filterable);

        return $query->orderBy('companies.id')->pluck('companies.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function seedFixtures(): void
    {
        $companyA = Company::query()->create(['tenant_id' => 1, 'name' => 'Acme']);
        $companyB = Company::query()->create(['tenant_id' => 1, 'name' => 'Beta']);

        $employeeA = Employee::query()->create([
            'company_id' => $companyA->id,
            'name' => 'Emp A',
            'salary' => 100,
            'status' => 'active',
            'hired_on' => '2025-01-01',
        ]);
        Employee::query()->create([
            'company_id' => $companyB->id,
            'name' => 'Emp B',
            'salary' => 200,
            'status' => 'pending',
            'hired_on' => '2025-06-01',
        ]);

        HealthDocument::query()->create([
            'employee_id' => $employeeA->id,
            'label' => 'A+',
            'score' => 90,
        ]);

        $ali = Author::query()->create(['name' => 'Ali Yilmaz']);
        $veli = Author::query()->create(['name' => 'Veli Demir']);

        $earlyTag = Tag::query()->create(['name' => 'news', 'tagged_on' => '2026-03-01']);
        $lateTag = Tag::query()->create(['name' => 'ops', 'tagged_on' => '2026-09-01']);

        $post1 = Post::query()->create([
            'author_id' => $ali->id,
            'tenant_id' => 1,
            'title' => 'Published One',
            'views' => 100,
            'status' => 'published',
            'published_on' => '2026-01-15',
            'notes' => 'pinned',
        ]);
        Post::query()->create([
            'author_id' => $veli->id,
            'tenant_id' => 1,
            'title' => 'Draft Two',
            'views' => 200,
            'status' => 'draft',
            'published_on' => '2026-05-01',
            'notes' => null,
        ]);
        Post::query()->create([
            'author_id' => $veli->id,
            'tenant_id' => 1,
            'title' => 'Archived Three',
            'views' => 0,
            'status' => 'archived',
            'published_on' => '2026-09-15',
            'notes' => null,
        ]);
        Post::query()->create([
            'author_id' => null,
            'tenant_id' => 1,
            'title' => 'Orphan Four',
            'views' => null,
            'status' => 'draft',
            'published_on' => null,
            'notes' => null,
        ]);
        Post::query()->create([
            'author_id' => $ali->id,
            'tenant_id' => 2,
            'title' => 'Other Tenant',
            'views' => 1000,
            'status' => 'published',
            'published_on' => '2026-01-15',
            'notes' => 'other',
        ]);

        $post1->tags()->attach([$earlyTag->id, $lateTag->id]);
    }
}

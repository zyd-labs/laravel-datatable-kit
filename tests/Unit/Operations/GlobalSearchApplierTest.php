<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Unit\Operations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Article;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Company;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Employee;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\HealthDocument;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\GlobalSearchApplier;

class GlobalSearchApplierTest extends TestCase
{
    private GlobalSearchApplier $globalSearchApplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->globalSearchApplier = new GlobalSearchApplier();
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            $table->foreignId('company_id')->constrained();
        });

        Schema::create('health_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->string('issued_by')->nullable();
        });

        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained();
            $table->foreignId('author_id')->constrained();
        });
    }

    public function test_single_level_belongs_to_search_uses_join_and_correct_column(): void
    {
        $query = HealthDocument::query();
        $this->globalSearchApplier->apply($query, 'Ali', ['employee.full_name']);

        $sql = $query->toSql();

        $this->assertStringContainsString('left join', strtolower($sql));
        $this->assertStringContainsString('employees', $sql);
        $this->assertStringContainsString('full_name', $sql);
        $this->assertStringContainsString('like', strtolower($sql));
        $this->assertStringNotContainsString('employees.company.name', $sql);
    }

    public function test_nested_belongs_to_search_uses_where_has_chain(): void
    {
        $company = Company::query()->create(['name' => 'ÖZLEM A.Ş.']);
        $employee = Employee::query()->create([
            'full_name' => 'Ayşe Yılmaz',
            'company_id' => $company->id,
        ]);
        HealthDocument::query()->create([
            'employee_id' => $employee->id,
            'issued_by' => 'İK',
        ]);

        $query = HealthDocument::query();
        $this->globalSearchApplier->apply($query, 'ÖZL', ['employee.company.name']);

        $sql = $query->toSql();

        $this->assertStringNotContainsString('employees.company.name', $sql);
        $this->assertStringContainsString('exists', strtolower($sql));
        $this->assertStringContainsString('name', $sql);
        $this->assertStringContainsString('like', strtolower($sql));

        $results = $query->get();
        $this->assertCount(1, $results);
    }

    public function test_mixed_searchable_fields_do_not_throw(): void
    {
        $query = HealthDocument::query();

        $this->globalSearchApplier->apply($query, 'ÖZL', [
            'employee.full_name',
            'employee.company.name',
        ]);

        $sql = $query->toSql();

        $this->assertStringNotContainsString('employees.company.name', $sql);
        $this->assertStringContainsString('full_name', $sql);
        $this->assertStringContainsString('exists', strtolower($sql));
    }

    public function test_has_many_with_nested_relation_uses_where_has_chain(): void
    {
        $query = Article::query();
        $this->globalSearchApplier->apply($query, 'John', ['posts.author.name']);

        $sql = $query->toSql();

        $this->assertStringNotContainsString('posts.author.name', $sql);
        $this->assertStringContainsString('exists', strtolower($sql));
        $this->assertStringContainsString('name', $sql);
    }

    public function test_direct_column_search_uses_base_table_prefix(): void
    {
        $query = HealthDocument::query();
        $this->globalSearchApplier->apply($query, 'HR', ['issued_by']);

        $sql = $query->toSql();

        $this->assertStringContainsString('health_documents', $sql);
        $this->assertStringContainsString('issued_by', $sql);
        $this->assertStringNotContainsString('exists', strtolower($sql));
    }
}

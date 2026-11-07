<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Author;
use ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models\Employee;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Employee::$tenantScopeId = 1;
        Author::$tenantScopeId = 1;
        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        Employee::$tenantScopeId = 1;
        Author::$tenantScopeId = 1;

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUpDatabase(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('health_documents');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('authors');
        Schema::dropIfExists('legacy_authors');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('tenant_id')->default(1);
            $table->string('name');
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('company_id')->nullable();
            $table->unsignedInteger('manager_id')->nullable();
            $table->unsignedInteger('tenant_id')->default(1);
            $table->string('name');
            $table->decimal('salary', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('hired_on')->nullable();
            $table->softDeletes();
        });

        Schema::create('authors', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('tenant_id')->default(1);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        Schema::create('legacy_authors', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamp('removed_at')->nullable();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
            $table->unsignedInteger('editor_id')->nullable();
            $table->unsignedInteger('legacy_author_id')->nullable();
            $table->unsignedInteger('tenant_id')->default(1);
            $table->string('title');
            $table->integer('views')->nullable();
            $table->string('status')->nullable();
            $table->date('published_on')->nullable();
            $table->string('notes')->nullable();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_published')->default(true);
            $table->date('tagged_on')->nullable();
            $table->softDeletes();
        });

        Schema::create('post_tag', function (Blueprint $table): void {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('tag_id');
            $table->boolean('is_active')->default(true);
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('health_documents', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('employee_id');
            $table->string('label');
            $table->integer('score')->nullable();
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('body');
            $table->nullableMorphs('commentable');
            $table->softDeletes();
        });
    }
}

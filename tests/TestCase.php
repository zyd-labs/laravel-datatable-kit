<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
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
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('health_documents');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('authors');
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
            $table->string('name');
            $table->decimal('salary', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->date('hired_on')->nullable();
        });

        Schema::create('authors', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
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
            $table->date('tagged_on')->nullable();
        });

        Schema::create('post_tag', function (Blueprint $table): void {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('tag_id');
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('health_documents', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('employee_id');
            $table->string('label');
            $table->integer('score')->nullable();
        });
    }
}

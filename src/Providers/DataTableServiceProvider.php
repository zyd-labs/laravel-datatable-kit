<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use ZydLabs\LaravelDataTableKit\Services\DataTable\DataTableManager;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Export\LaravelExcelExporter;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\FilterApplier;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\GlobalSearchApplier;
use ZydLabs\LaravelDataTableKit\Services\DataTable\Operations\Sorter;

final class DataTableServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/datatable.php', 'datatable');

        $this->app->singleton(DataTableManager::class, function (Container $container): DataTableManager {
            return new DataTableManager(
                $container,
                new GlobalSearchApplier(),
                new FilterApplier(),
                new Sorter(),
                new LaravelExcelExporter()
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/datatable.php' => config_path('datatable.php'),
        ], 'datatable-config');
    }
}


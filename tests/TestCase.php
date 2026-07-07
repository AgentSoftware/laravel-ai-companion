<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests;

use AgentSoftware\LaravelAiCompanion\LaravelAiCompanionServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            LaravelAiCompanionServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function defineDatabase(): void
    {
        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function beforeRefreshingDatabase(): void
    {
        // Enable foreign key constraints before migrations run so they're created with enforcement
        if (DB::getDriverName() === 'sqlite') {
            try {
                DB::statement('PRAGMA foreign_keys = ON');
            } catch (\Exception $e) {
                // Ignore errors
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests;

use AgentSoftware\LaravelAiCompanion\LaravelAiCompanionServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

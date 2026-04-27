<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiTokenTracker\Tests;

use AgentSoftware\LaravelAiTokenTracker\LaravelAiTokenTrackerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelAiTokenTrackerServiceProvider::class,
        ];
    }
}

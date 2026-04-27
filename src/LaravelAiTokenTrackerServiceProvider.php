<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiTokenTracker;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelAiTokenTrackerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-ai-token-tracker')
            ->hasMigration('create_ai_token_usages_table');
    }
}

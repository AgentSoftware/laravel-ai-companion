<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\LaravelAiCompanionServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

it('does not scan for migrations to publish when the app boots', function () {
    unset(
        ServiceProvider::$publishes[LaravelAiCompanionServiceProvider::class],
        ServiceProvider::$publishGroups['ai-companion-migrations'],
    );

    $this->refreshApplication();

    expect(ServiceProvider::pathsToPublish(group: 'ai-companion-migrations'))->toBe([]);
});

it('publishes every package migration with vendor:publish', function () {
    $databasePath = sys_get_temp_dir().'/ai-companion-publish-'.uniqid();
    $this->app->useDatabasePath($databasePath);

    $migrationNames = fn (string $directory): array => collect(File::files($directory))
        ->map(fn (SplFileInfo $file): string => preg_replace('/^(\d{4}_\d{2}_\d{2}_\d{6}_)+/', '', $file->getFilename()))
        ->sort()
        ->values()
        ->all();

    try {
        $this->artisan('vendor:publish', ['--tag' => 'ai-companion-migrations'])->assertSuccessful();

        expect($migrationNames("{$databasePath}/migrations"))
            ->toBe($migrationNames(__DIR__.'/../../database/migrations'))
            ->not->toBeEmpty();
    } finally {
        File::deleteDirectory($databasePath);
    }
});

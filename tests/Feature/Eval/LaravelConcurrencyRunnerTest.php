<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\LaravelConcurrencyRunner;
use Illuminate\Support\Facades\Concurrency;

it('delegates to the process driver explicitly, not whatever the app defaults to', function (): void {
    $driver = Mockery::mock();
    $driver->shouldReceive('run')->once()->andReturn(['a', 'b']);

    Concurrency::shouldReceive('driver')->once()->with('process')->andReturn($driver);

    $tasks = [fn (): int => 1, fn (): int => 2];

    expect((new LaravelConcurrencyRunner)->run($tasks))->toBe(['a', 'b']);
});

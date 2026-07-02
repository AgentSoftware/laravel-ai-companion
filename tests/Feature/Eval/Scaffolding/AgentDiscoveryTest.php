<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\AgentDiscovery;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\FixtureAgent;

it('discovers concrete agent classes under a PSR-4 root', function (): void {
    $discovery = new AgentDiscovery(
        path: dirname(__DIR__, 3).'/Support',
        namespace: 'AgentSoftware\\LaravelAiCompanion\\Tests\\Support\\',
    );

    $found = $discovery->discover();

    expect($found)->toContain(FixtureAgent::class);
});

it('returns an empty array for a path with no agents', function (): void {
    $discovery = new AgentDiscovery(
        path: sys_get_temp_dir().'/definitely-empty-'.uniqid(),
        namespace: 'App\\',
    );

    expect($discovery->discover())->toBe([]);
});

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding;

use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;

final class ScalarDefaultsFixtureAgent extends StubAgent
{
    public function __construct(
        public float $temperature,
        public bool $verbose,
        mixed $untyped,
    ) {}
}

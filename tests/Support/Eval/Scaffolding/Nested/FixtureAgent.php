<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\Nested;

use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;

/**
 * Shares a basename with ..\FixtureAgent so the scaffold command's agent
 * picker has to disambiguate duplicate short names.
 */
final class FixtureAgent extends StubAgent
{
    public function __construct(public string $tone = 'neutral') {}
}

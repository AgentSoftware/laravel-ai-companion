<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalEnvironment;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalHarness;

class StubHarness implements EvalHarness
{
    public function boot(array $row): EvalEnvironment
    {
        return new StubEnvironment;
    }

    public function context(EvalEnvironment $environment): ?object
    {
        return null;
    }

    public function experimentMetadata(): array
    {
        return ['catalogue' => ['x']];
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalHarness;

class StubHarness implements EvalHarness
{
    public function boot(array $row): object
    {
        return (object) ['row' => $row];
    }

    public function context(object $environment): array
    {
        return ['stub' => $environment];
    }

    public function experimentMetadata(): array
    {
        return ['catalogue' => ['x']];
    }
}

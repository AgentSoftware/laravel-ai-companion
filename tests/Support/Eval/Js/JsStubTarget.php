<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Js;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalEnvironment;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;
use Laravel\Ai\Contracts\Agent;

final class JsStubTarget implements EvalTarget
{
    /** @param array<int, Scorer> $scorers */
    public function __construct(private array $scorers = []) {}

    public function key(): string
    {
        return 'js-stub';
    }

    public function label(): string
    {
        return 'JS Stub';
    }

    public function defaultDataset(): string
    {
        return 'database/eval-datasets/js-stub.json';
    }

    public function promptInput(array $row): string
    {
        return (string) ($row['prompt'] ?? '');
    }

    public function scorers(): array
    {
        return $this->scorers;
    }

    public function agent(EvalEnvironment $environment, array $row = []): Agent
    {
        return new StubAgent;
    }

    public function subjectInput(array $row): array
    {
        return [];
    }
}

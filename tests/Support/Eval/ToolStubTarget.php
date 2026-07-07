<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalEnvironment;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use Laravel\Ai\Contracts\Agent;

class ToolStubTarget implements EvalTarget
{
    public function key(): string
    {
        return 'stub-tool';
    }

    public function label(): string
    {
        return 'Tool stub';
    }

    public function defaultDataset(): string
    {
        return 'eval-dataset.json';
    }

    public function promptInput(array $row): string
    {
        return (string) ($row['brief'] ?? '');
    }

    public function scorers(): array
    {
        return [new CapturingScorer];
    }

    public function agent(EvalEnvironment $environment, array $row = []): Agent
    {
        return ToolStubAgent::make();
    }

    public function subjectInput(array $row): array
    {
        return [];
    }
}

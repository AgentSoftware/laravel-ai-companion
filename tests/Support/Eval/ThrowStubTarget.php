<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use Laravel\Ai\Contracts\Agent;
use RuntimeException;

class ThrowStubTarget implements EvalTarget
{
    public function key(): string
    {
        return 'stub-throw';
    }

    public function label(): string
    {
        return 'Throwing stub';
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
        return [new FixedScorer('routing', 1.0)];
    }

    public function agent(object $environment, array $row = []): Agent
    {
        throw new RuntimeException('boom');
    }

    public function subjectInput(array $row): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Online;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalEnvironment;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;
use Laravel\Ai\Contracts\Agent;

final class OnlineStubTarget implements EvalTarget
{
    /** @param array<int, Scorer> $scorers */
    public function __construct(private array $scorers) {}

    public function key(): string
    {
        return 'online-stub';
    }

    public function label(): string
    {
        return 'Online Stub';
    }

    public function defaultDataset(): string
    {
        return 'database/eval-datasets/online-stub.json';
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

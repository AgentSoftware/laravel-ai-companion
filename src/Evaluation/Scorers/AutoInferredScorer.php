<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Scorers;

class AutoInferredScorer extends Scorer
{
    public function __construct(private readonly string $agentClass) {}

    public function agent(): string
    {
        return $this->agentClass;
    }

    /** @return array<string, string> */
    public function criteria(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Scorers;

abstract class Scorer
{
    abstract public function agent(): string;

    /**
     * Criteria to evaluate against: name → description of what "good" looks like.
     * Return an empty array to let the judge infer criteria from the system prompt.
     *
     * @return array<string, string>
     */
    abstract public function criteria(): array;
}

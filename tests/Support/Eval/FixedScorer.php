<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

class FixedScorer implements Scorer
{
    public function __construct(private string $scoreName, private float $value) {}

    public function score(EvalSubject $subject): Score
    {
        return new Score($this->scoreName, $this->value);
    }
}

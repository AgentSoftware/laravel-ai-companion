<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

class CapturingScorer implements Scorer
{
    public static ?EvalSubject $subject = null;

    public function score(EvalSubject $subject): Score
    {
        self::$subject = $subject;

        return new Score('capture', 1.0);
    }
}

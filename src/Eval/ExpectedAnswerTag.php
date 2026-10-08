<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

enum ExpectedAnswerTag: string
{
    /** A yes the classifier must never miss — a miss fails the run. */
    case MustCatch = 'must_catch';

    /** A no the classifier should not flag — a flag is a false hold. */
    case MustPass = 'must_pass';
}

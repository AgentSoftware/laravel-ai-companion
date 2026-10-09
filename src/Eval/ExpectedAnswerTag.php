<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

enum ExpectedAnswerTag: string
{
    /** A yes the classifier must never miss — a miss fails the run. */
    case MustCatch = 'must_catch';

    /** A no the classifier must not flag — a flag is a false positive. */
    case MustPass = 'must_pass';
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

/**
 * A plain, real class (not a Pest-generated test class) so its methods can
 * be referenced as first-class callables that survive process-driver
 * serialization in LaravelConcurrencyRunnerTest.
 */
class ArithmeticTask
{
    public static function double(): int
    {
        return 1 + 1;
    }

    public static function quadruple(): int
    {
        return 2 + 2;
    }
}

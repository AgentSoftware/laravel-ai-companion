<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\LaravelConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\ArithmeticTask;

it('runs tasks via the process driver and returns their results in order', function (): void {
    $runner = new LaravelConcurrencyRunner;

    $results = $runner->run([
        ArithmeticTask::double(...),
        ArithmeticTask::quadruple(...),
    ]);

    expect($results)->toBe([2, 4]);
});

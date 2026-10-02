<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Middleware\TraceAiResponse;

it('passes each generation step straight through', function () {
    $result = makeStepResult();

    expect((new TraceAiResponse)->handle(makePendingStep(), fn () => $result))->toBe($result);
});

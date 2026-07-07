<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\FirstTurnToolCallScorer;

it('scores 0.0 when the first step called no tools', function (): void {
    $scorer = new FirstTurnToolCallScorer;

    $subject = new EvalSubject(output: [], input: ['first_step_tool_calls' => []]);

    expect($scorer->score($subject)->score)->toBe(0.0);
});

it('scores 1.0 when the first step called at least one tool', function (): void {
    $scorer = new FirstTurnToolCallScorer;

    $subject = new EvalSubject(output: [], input: ['first_step_tool_calls' => ['write_text']]);

    expect($scorer->score($subject)->score)->toBe(1.0)
        ->and($scorer->score($subject)->metadata['called'])->toBe(['write_text']);
});

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\ToolUsageScorer;

it('passes when any tool was called', function (): void {
    $score = new ToolUsageScorer()->score(new EvalSubject(
        output: ['text' => 'done'],
        input: ['tool_calls' => ['WriteTextTool', 'SearchTool']],
    ));

    expect($score->score)->toBe(1.0)
        ->and($score->name)->toBe('used_tools')
        ->and($score->metadata['matching_tool_calls'])->toBe(2);
});

it('fails when no tools were called', function (): void {
    $score = new ToolUsageScorer()->score(new EvalSubject(output: ['text' => 'chatty answer'], input: []));

    expect($score->score)->toBe(0.0)
        ->and($score->metadata['matching_tool_calls'])->toBe(0);
});

it('filters by wildcard pattern and honours min', function (): void {
    $subject = new EvalSubject(
        output: ['text' => 'done'],
        input: ['tool_calls' => ['WriteTextTool', 'WriteImageTool', 'SearchTool', 42]],
    );

    expect(new ToolUsageScorer(name: 'wrote', pattern: 'Write*', min: 2)->score($subject)->score)->toBe(1.0)
        ->and(new ToolUsageScorer(pattern: 'Write*', min: 3)->score($subject)->score)->toBe(0.0)
        ->and(new ToolUsageScorer(pattern: 'Search*')->score($subject)->metadata['matching_tool_calls'])->toBe(1);
});

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\RequiresExpected;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\ToolRoutingScorer;

it('scores a perfect route as 1.0 and canonicalises tool names', function (): void {
    $scorer = new ToolRoutingScorer;

    $subject = new EvalSubject(output: [], input: [
        'tool_calls' => ['WriteCopyTool'],
        'expects_tool' => ['write_copy'],
    ]);

    expect($scorer->score($subject)->score)->toBe(1.0);
});

it('penalises an extra unexpected call with Jaccard', function (): void {
    $scorer = new ToolRoutingScorer;

    $subject = new EvalSubject(output: [], input: [
        'tool_calls' => ['write_copy', 'delete_block'],
        'expects_tool' => ['write_copy'],
    ]);

    expect($scorer->score($subject)->score)->toBe(0.5);
});

it('passes when no tool is expected', function (): void {
    $scorer = new ToolRoutingScorer;

    $score = $scorer->score(new EvalSubject(output: [], input: [
        'tool_calls' => ['write_copy'],
        'expects_tool' => [],
    ]));

    expect($score->score)->toBe(1.0)
        ->and($score->metadata['note'])->toBe('no expected tool set');
});

it('scores a correct decline as 1.0 and a wrongful call as 0.0', function (): void {
    $scorer = new ToolRoutingScorer(declinePhrase: 'outside of my capabilities');

    $declined = new EvalSubject(output: [], input: [
        'tool_calls' => [],
        'expects_decline' => true,
        'text' => 'That is outside of my capabilities.',
    ]);
    $acted = new EvalSubject(output: [], input: [
        'tool_calls' => ['write_copy'],
        'expects_decline' => true,
        'text' => 'Sure!',
    ]);

    expect($scorer->score($declined)->score)->toBe(1.0)
        ->and($scorer->score($declined)->metadata['standard_wording'])->toBeTrue()
        ->and($scorer->score($acted)->score)->toBe(0.0);
});

it('is marked as requiring expected context', function (): void {
    expect(new ToolRoutingScorer)
        ->toBeInstanceOf(RequiresExpected::class);
});

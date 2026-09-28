<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Judges\JudgeAgent;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\LlmJudgeScorer;

it('normalises the judge rating to 0-1 against the scale', function (): void {
    JudgeAgent::fake(fn (): array => ['rating' => 8, 'reasoning' => 'on topic']);

    $scorer = new LlmJudgeScorer(name: 'summarises_brief', rubric: 'rate the name', scale: 10, input: 'brief', output: 'name');

    $score = $scorer->score(new EvalSubject(
        output: ['name' => 'Spring Sale Launch'],
        input: ['brief' => 'Announce the spring sale'],
    ));

    expect($score->score)->toBe(0.8)
        ->and($score->metadata['rating'])->toBe(8)
        ->and($score->metadata['reasoning'])->toBe('on topic');
});

it('scores 0.0 without calling the judge when the candidate is missing', function (): void {
    JudgeAgent::fake(fn (): array => ['rating' => 9, 'reasoning' => 'unused']);

    $scorer = new LlmJudgeScorer(name: 'summarises_brief', rubric: 'rate the name', scale: 10, input: 'brief', output: 'name');

    $score = $scorer->score(new EvalSubject(
        output: [],
        input: ['brief' => 'Announce the spring sale'],
    ));

    expect($score->score)->toBe(0.0)
        ->and($score->metadata['reason'])->toBe('missing input or output');

    JudgeAgent::assertNeverPrompted();
});

it('judges the reply text when a run with no tool calls has no transcript', function (): void {
    JudgeAgent::fake(fn (): array => ['rating' => 10, 'reasoning' => 'declined politely']);

    $scorer = new LlmJudgeScorer(name: 'behaviour', rubric: 'rate the run', scale: 10, input: 'input', output: 'transcript');

    $score = $scorer->score(new EvalSubject(
        output: ['text' => 'Sorry, I can only help with property records.', 'tool_calls' => []],
        input: ['input' => 'Write me a poem'],
    ));

    expect($score->score)->toBe(1.0);

    JudgeAgent::assertPrompted(fn ($prompt): bool => $prompt->prompt === 'Sorry, I can only help with property records.');
});

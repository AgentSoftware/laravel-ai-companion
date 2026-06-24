<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

it('exposes the output and defaults context and input to empty arrays', function () {
    $subject = new EvalSubject(['blocks' => []]);

    expect($subject->output)->toBe(['blocks' => []])
        ->and($subject->context)->toBe([])
        ->and($subject->input)->toBe([]);
});

it('retains the context and input passed to the eval subject', function () {
    $subject = new EvalSubject(
        output: ['blocks' => ['hero']],
        context: ['catalogue_ids' => ['hero/a']],
        input: ['brief' => 'Make it pop'],
    );

    expect($subject->output)->toBe(['blocks' => ['hero']])
        ->and($subject->context)->toBe(['catalogue_ids' => ['hero/a']])
        ->and($subject->input)->toBe(['brief' => 'Make it pop']);
});

it('exposes the name and score and defaults metadata to an empty array', function () {
    $score = new Score('catalogue_valid', 1.0);

    expect($score->name)->toBe('catalogue_valid')
        ->and($score->score)->toBe(1.0)
        ->and($score->metadata)->toBe([]);
});

it('retains the metadata passed to the score', function () {
    $score = new Score('hydrates_clean', 0.5, ['reason' => 'partial']);

    expect($score->name)->toBe('hydrates_clean')
        ->and($score->score)->toBe(0.5)
        ->and($score->metadata)->toBe(['reason' => 'partial']);
});

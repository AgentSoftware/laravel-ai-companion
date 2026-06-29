<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

it('exposes the output and defaults context to null and input to an empty array', function () {
    $subject = new EvalSubject(['blocks' => []]);

    expect($subject->output)->toBe(['blocks' => []])
        ->and($subject->context)->toBeNull()
        ->and($subject->input)->toBe([]);
});

it('retains the context object and input passed to the eval subject', function () {
    $context = (object) ['catalogue_ids' => ['hero/a']];

    $subject = new EvalSubject(
        output: ['blocks' => ['hero']],
        context: $context,
        input: ['brief' => 'Make it pop'],
    );

    expect($subject->output)->toBe(['blocks' => ['hero']])
        ->and($subject->context)->toBe($context)
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

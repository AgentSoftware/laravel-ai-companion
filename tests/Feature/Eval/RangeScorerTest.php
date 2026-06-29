<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\RangeScorer;

it('passes when a string field is within the word bounds', function (): void {
    $scorer = new RangeScorer(name: 'name_word_count', field: 'name', mode: 'words', min: 3, max: 8);

    $score = $scorer->score(new EvalSubject(output: ['name' => 'Spring garden sale event']));

    expect($score->score)->toBe(1.0)
        ->and($score->metadata['count'])->toBe(4);
});

it('fails when a string field is outside the word bounds', function (): void {
    $scorer = new RangeScorer(name: 'name_word_count', field: 'name', mode: 'words', min: 3, max: 8);

    $score = $scorer->score(new EvalSubject(output: ['name' => 'Sale']));

    expect($score->score)->toBe(0.0)
        ->and($score->metadata['count'])->toBe(1);
});

it('counts characters in chars mode', function (): void {
    $scorer = new RangeScorer(name: 'len', field: 'name', mode: 'chars', max: 5);

    expect($scorer->score(new EvalSubject(output: ['name' => 'abcde']))->score)->toBe(1.0)
        ->and($scorer->score(new EvalSubject(output: ['name' => 'abcdef']))->score)->toBe(0.0);
});

it('counts array items in items mode', function (): void {
    $scorer = new RangeScorer(name: 'slots', field: 'slots', mode: 'items', min: 2);

    expect($scorer->score(new EvalSubject(output: ['slots' => ['a', 'b']]))->score)->toBe(1.0)
        ->and($scorer->score(new EvalSubject(output: ['slots' => ['a']]))->score)->toBe(0.0);
});

it('treats a missing field as zero', function (): void {
    $scorer = new RangeScorer(name: 'name_word_count', field: 'name', mode: 'words', min: 1);

    expect($scorer->score(new EvalSubject(output: []))->score)->toBe(0.0);
});

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\MatchScorer;

it('scores exact string equality', function (): void {
    $scorer = new MatchScorer(name: 'category', field: 'category', expected: 'expected', mode: 'exact');

    expect($scorer->score(new EvalSubject(output: ['category' => 'newsletter'], input: ['expected' => 'newsletter']))->score)->toBe(1.0)
        ->and($scorer->score(new EvalSubject(output: ['category' => 'promo'], input: ['expected' => 'newsletter']))->score)->toBe(0.0);
});

it('scores substring containment case-insensitively', function (): void {
    $scorer = new MatchScorer(name: 'mentions', field: 'body', expected: 'expected', mode: 'contains');

    expect($scorer->score(new EvalSubject(output: ['body' => 'Our SPRING sale is on'], input: ['expected' => 'spring sale']))->score)->toBe(1.0)
        ->and($scorer->score(new EvalSubject(output: ['body' => 'Our winter sale'], input: ['expected' => 'spring sale']))->score)->toBe(0.0);
});

it('scores list overlap with Jaccard', function (): void {
    $scorer = new MatchScorer(name: 'topics', field: 'topics', expected: 'expected', mode: 'overlap');

    $subject = new EvalSubject(
        output: ['topics' => ['a', 'b', 'c']],
        input: ['expected' => ['a', 'b']],
    );

    expect($scorer->score($subject)->score)->toBe(2 / 3);
});

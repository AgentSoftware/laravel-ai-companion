<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Evaluation\Results\EvaluationResult;

it('computes overall_score as 0 when the judge returns empty criteria', function (): void {
    $result = EvaluationResult::fromArray(
        ['criteria' => [], 'summary' => 'Nothing to evaluate.'],
        'gemini-3.5-flash',
    );

    expect($result->overallScore)->toBe(0)
        ->and($result->criteria)->toBeEmpty();
});

it('computes overall_score as the arithmetic mean of criteria scores', function (): void {
    $result = EvaluationResult::fromArray([
        'criteria' => [
            ['name' => 'accuracy', 'score' => 80, 'feedback' => 'Good.'],
            ['name' => 'tone',     'score' => 60, 'feedback' => 'Acceptable.'],
        ],
        'summary' => 'Decent response.',
    ], 'gemini-3.5-flash');

    expect($result->overallScore)->toBe(70)
        ->and($result->criteria)->toHaveCount(2);
});

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\ExpectedAnswer;
use AgentSoftware\LaravelAiCompanion\Eval\ExpectedAnswerTag;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\BooleanAnswerScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\ChoiceAnswerScorer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

function classifiedSubject(array $answers, array $expected): EvalSubject
{
    return new EvalSubject(output: [], answers: $answers, expectedAnswers: ExpectedAnswer::fromDataset($expected));
}

it('reads bare and tagged expected answers from a dataset row', function (): void {
    $expected = ExpectedAnswer::fromDataset([
        'priority' => 'urgent',
        'gas' => ['answer' => true, 'tag' => 'must_catch'],
        'fire' => ['answer' => false],
    ]);

    expect($expected['priority'])->toEqual(new ExpectedAnswer('urgent'))
        ->and($expected['gas'])->toEqual(new ExpectedAnswer(true, ExpectedAnswerTag::MustCatch))
        ->and($expected['fire']->toArray())->toBe(['answer' => false])
        ->and($expected['gas']->toArray())->toBe(['answer' => true, 'tag' => 'must_catch']);
});

it('reads the acceptable choices of an expected answer', function (): void {
    $expected = ExpectedAnswer::fromDataset(['priority' => ['answer' => 'urgent', 'acceptable' => ['emergency']]])['priority'];

    expect($expected->acceptable)->toBe(['emergency'])
        ->and($expected->accepts('urgent'))->toBeTrue()
        ->and($expected->accepts('emergency'))->toBeTrue()
        ->and($expected->accepts('routine'))->toBeFalse()
        ->and($expected->toArray())->toBe(['answer' => 'urgent', 'acceptable' => ['emergency']]);
});

it('rejects acceptable answers that are not a list of choices for a choice question', function (array $value): void {
    ExpectedAnswer::fromDataset(['priority' => $value]);
})->with([
    'not a list' => [['answer' => 'urgent', 'acceptable' => 'emergency']],
    'keyed' => [['answer' => 'urgent', 'acceptable' => ['a' => 'emergency']]],
    'not choices' => [['answer' => 'urgent', 'acceptable' => [1]]],
    'on a boolean question' => [['answer' => true, 'acceptable' => ['yes']]],
])->throws(InvalidArgumentException::class, 'Acceptable answers must be a list of choices for a choice question');

it('rejects an unknown expected answer tag rather than dropping the gate', function (): void {
    ExpectedAnswer::fromDataset(['gas' => ['answer' => true, 'tag' => 'must_cath']]);
})->throws(ValueError::class);

it('rejects an expected answer that is not true, false or a choice', function (mixed $value): void {
    ExpectedAnswer::fromDataset(['gas' => $value]);
})->with([
    'a number' => [1],
    'null' => [null],
    'an object without an answer' => [['tag' => 'must_catch']],
])->throws(InvalidArgumentException::class, 'An expected answer must be true, false or a choice');

it('scores a boolean answer against the expected answer at the threshold', function (): void {
    $scorer = new BooleanAnswerScorer('gas', threshold: 0.7);

    $hit = $scorer->score(classifiedSubject(['gas' => new BooleanAnswer(0.75)], ['gas' => true]));
    $miss = $scorer->score(classifiedSubject(['gas' => new BooleanAnswer(0.65)], ['gas' => true]));

    expect($hit->name)->toBe('gas')
        ->and($hit->score)->toBe(1.0)
        ->and($hit->blocking)->toBeFalse()
        ->and($hit->metadata)->toBe([
            'expected' => true,
            'actual' => true,
            'probability' => 0.75,
            'threshold' => 0.7,
            'confusion' => ['expected' => 'true', 'actual' => 'true'],
        ])
        ->and($miss->score)->toBe(0.0)
        ->and($miss->metadata['confusion'])->toBe(['expected' => 'true', 'actual' => 'false']);
});

it('skips a boolean question the row expects nothing for', function (): void {
    $score = new BooleanAnswerScorer('gas')->score(classifiedSubject(['gas' => new BooleanAnswer(0.9)], []));

    expect($score->skipped)->toBeTrue()
        ->and($score->name)->toBe('gas');
});

it('measures must-catch rows as a blocking score and skips untagged rows', function (): void {
    $scorer = new BooleanAnswerScorer('gas', tag: ExpectedAnswerTag::MustCatch);

    $caught = $scorer->score(classifiedSubject(['gas' => new BooleanAnswer(0.9)], ['gas' => ['answer' => true, 'tag' => 'must_catch']]));
    $untagged = $scorer->score(classifiedSubject(['gas' => new BooleanAnswer(0.9)], ['gas' => true]));

    expect($caught->name)->toBe('gas_must_catch')
        ->and($caught->score)->toBe(1.0)
        ->and($caught->blocking)->toBeTrue()
        ->and($caught->metadata)->not->toHaveKey('confusion')
        ->and($untagged->skipped)->toBeTrue()
        ->and($untagged->name)->toBe('gas_must_catch');
});

it('measures must-pass rows as a non-blocking false-positive score', function (): void {
    $held = new BooleanAnswerScorer('gas', tag: ExpectedAnswerTag::MustPass)
        ->score(classifiedSubject(['gas' => new BooleanAnswer(0.6)], ['gas' => ['answer' => false, 'tag' => 'must_pass']]));

    expect($held->name)->toBe('gas_must_pass')
        ->and($held->score)->toBe(0.0)
        ->and($held->blocking)->toBeFalse();
});

it('refuses to score a boolean question that has no boolean answer', function (): void {
    new BooleanAnswerScorer('gas')->score(classifiedSubject(['gas' => new ChoiceAnswer('yes', [])], ['gas' => true]));
})->throws(InvalidArgumentException::class, 'No boolean answer to score for question [gas].');

it('scores a choice answer by exact match', function (): void {
    $scorer = new ChoiceAnswerScorer('priority');
    $answer = new ChoiceAnswer('urgent', ['routine' => 0.3, 'urgent' => 0.7], 0.7);

    $hit = $scorer->score(classifiedSubject(['priority' => $answer], ['priority' => 'urgent']));
    $miss = $scorer->score(classifiedSubject(['priority' => $answer], ['priority' => 'routine']));

    expect($hit->score)->toBe(1.0)
        ->and($hit->metadata)->toBe([
            'expected' => 'urgent',
            'actual' => 'urgent',
            'probabilities' => ['routine' => 0.3, 'urgent' => 0.7],
            'confidence' => 0.7,
            'confusion' => ['expected' => 'urgent', 'actual' => 'urgent'],
        ])
        ->and($miss->score)->toBe(0.0);
});

it('skips a choice question the row expects nothing for', function (): void {
    expect(new ChoiceAnswerScorer('priority')->score(classifiedSubject([], []))->skipped)->toBeTrue();
});

it('refuses to score a choice question that has no choice answer', function (): void {
    new ChoiceAnswerScorer('priority')->score(classifiedSubject([], ['priority' => 'urgent']));
})->throws(InvalidArgumentException::class, 'No choice answer to score for question [priority].');

it('refuses a boolean question whose expected answer is not true or false', function (): void {
    new BooleanAnswerScorer('gas')->score(classifiedSubject(['gas' => new BooleanAnswer(0.9)], ['gas' => 'yes']));
})->throws(InvalidArgumentException::class, 'The expected answer for boolean question [gas] must be true or false.');

it('refuses a choice question whose expected answer is not an option', function (): void {
    new ChoiceAnswerScorer('priority')->score(classifiedSubject(['priority' => new ChoiceAnswer('urgent', [])], ['priority' => true]));
})->throws(InvalidArgumentException::class, 'The expected answer for choice question [priority] must be one of its options.');

it('counts any acceptable choice as right on the acceptable score', function (): void {
    $scorer = new ChoiceAnswerScorer('priority', acceptable: true);
    $expected = ['priority' => ['answer' => 'urgent', 'acceptable' => ['emergency']]];

    $close = $scorer->score(classifiedSubject(['priority' => new ChoiceAnswer('emergency', ['urgent' => 0.4, 'emergency' => 0.6], 0.6)], $expected));
    $wrong = $scorer->score(classifiedSubject(['priority' => new ChoiceAnswer('routine', ['routine' => 1.0], 1.0)], $expected));
    $exact = new ChoiceAnswerScorer('priority')->score(classifiedSubject(['priority' => new ChoiceAnswer('emergency', [], 0.6)], $expected));

    expect($close->name)->toBe('priority_acceptable')
        ->and($close->score)->toBe(1.0)
        ->and($close->metadata['acceptable'])->toBe(['emergency'])
        ->and($close->metadata)->not->toHaveKey('confusion')
        ->and($wrong->score)->toBe(0.0)
        ->and($exact->score)->toBe(0.0);
});

it('skips the acceptable score for a row that expects nothing for the question', function (): void {
    $score = new ChoiceAnswerScorer('priority', acceptable: true)->score(classifiedSubject([], []));

    expect($score->skipped)->toBeTrue()
        ->and($score->name)->toBe('priority_acceptable');
});

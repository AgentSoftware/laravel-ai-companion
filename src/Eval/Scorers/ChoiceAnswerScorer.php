<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use InvalidArgumentException;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

/**
 * Exact match of a classification's choice question against the row's expected
 * option. With `acceptable`, the score is named `{question}_acceptable` and
 * also counts the row's other acceptable options as right. Rows that expect
 * nothing for the question are skipped.
 */
final readonly class ChoiceAnswerScorer implements Scorer
{
    public function __construct(
        private string $question,
        private bool $acceptable = false,
    ) {}

    public function score(EvalSubject $subject): Score
    {
        $name = $this->acceptable ? "{$this->question}_acceptable" : $this->question;
        $expected = $subject->expectedAnswers[$this->question] ?? null;

        if ($expected === null) {
            return Score::skipped($name);
        }

        $answer = $subject->answers[$this->question] ?? null;

        if (! $answer instanceof ChoiceAnswer) {
            throw new InvalidArgumentException("No choice answer to score for question [{$this->question}].");
        }

        if (! is_string($expected->answer)) {
            throw new InvalidArgumentException("The expected answer for choice question [{$this->question}] must be one of its options.");
        }

        $right = $this->acceptable ? $expected->accepts($answer->choice) : $answer->choice === $expected->answer;

        return new Score($name, $right ? 1.0 : 0.0, [
            'expected' => $expected->answer,
            ...($this->acceptable ? ['acceptable' => $expected->acceptable] : []),
            'actual' => $answer->choice,
            'probabilities' => $answer->probabilities,
            'confidence' => $answer->confidence,
            // One matrix per question: the acceptable score would only repeat it.
            ...($this->acceptable ? [] : ['confusion' => ['expected' => $expected->answer, 'actual' => $answer->choice]]),
        ]);
    }
}

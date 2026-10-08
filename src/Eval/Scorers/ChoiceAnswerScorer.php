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
 * option. Rows that expect nothing for the question are skipped.
 */
final readonly class ChoiceAnswerScorer implements Scorer
{
    public function __construct(private string $question) {}

    public function score(EvalSubject $subject): Score
    {
        $expected = $subject->expectedAnswers[$this->question] ?? null;

        if ($expected === null) {
            return Score::skipped($this->question);
        }

        $answer = $subject->answers[$this->question] ?? null;

        if (! $answer instanceof ChoiceAnswer) {
            throw new InvalidArgumentException("No choice answer to score for question [{$this->question}].");
        }

        return new Score($this->question, $answer->choice === $expected->answer ? 1.0 : 0.0, [
            'expected' => $expected->answer,
            'actual' => $answer->choice,
            'probabilities' => $answer->probabilities,
            'confidence' => $answer->confidence,
            'confusion' => ['expected' => (string) $expected->answer, 'actual' => $answer->choice],
        ]);
    }
}

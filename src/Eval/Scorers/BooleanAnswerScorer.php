<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\ExpectedAnswerTag;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use InvalidArgumentException;
use Laravel\Ai\Responses\Data\BooleanAnswer;

/**
 * Scores a classification's yes/no question against the row's expected
 * answer: the answer is "yes" when its probability meets `threshold`.
 *
 * Narrow it to one tag to measure a gate. With `MustCatch` the score is named
 * `{question}_must_catch`, averages to recall over the tagged rows, and a miss
 * fails the run; with `MustPass` it is `{question}_must_pass`, averaging to one
 * minus the false-hold rate. Rows without the tag are skipped.
 */
final readonly class BooleanAnswerScorer implements Scorer
{
    public function __construct(
        private string $question,
        private float $threshold = 0.5,
        private ?ExpectedAnswerTag $tag = null,
    ) {}

    public function score(EvalSubject $subject): Score
    {
        $name = $this->tag === null ? $this->question : "{$this->question}_{$this->tag->value}";
        $expected = $subject->expectedAnswers[$this->question] ?? null;

        if ($expected === null || ($this->tag !== null && $expected->tag !== $this->tag)) {
            return Score::skipped($name);
        }

        $answer = $subject->answers[$this->question] ?? null;

        if (! $answer instanceof BooleanAnswer) {
            throw new InvalidArgumentException("No boolean answer to score for question [{$this->question}].");
        }

        $actual = $answer->isTrue($this->threshold);

        return new Score($name, $actual === $expected->answer ? 1.0 : 0.0, [
            'expected' => $expected->answer,
            'actual' => $actual,
            'probability' => $answer->probability,
            'threshold' => $this->threshold,
            // One matrix per question: the tag-narrowed scores would only repeat a slice of it.
            ...($this->tag === null ? ['confusion' => ['expected' => var_export($expected->answer, true), 'actual' => var_export($actual, true)]] : []),
        ], blocking: $this->tag === ExpectedAnswerTag::MustCatch);
    }
}

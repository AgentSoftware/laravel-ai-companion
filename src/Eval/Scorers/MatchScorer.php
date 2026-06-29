<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use Illuminate\Support\Str;

/**
 * Compares an output field against an expected value carried on the dataset row
 * (subject input). `mode` picks the comparison: `exact` string equality,
 * `contains` (the expected string appears in the output), or `overlap` (Jaccard
 * of two lists — a missing or extra element both drop the score).
 */
final class MatchScorer implements Scorer
{
    public function __construct(
        private string $name,
        private string $field,
        private string $expected,
        private string $mode = 'exact',
    ) {}

    public function score(EvalSubject $subject): Score
    {
        $actual = $subject->output[$this->field] ?? null;
        $expected = $subject->input[$this->expected] ?? null;

        if ($this->mode === 'overlap') {
            return $this->scoreOverlap($actual, $expected);
        }

        $actualText = trim((string) (is_array($actual) ? '' : $actual));
        $expectedText = trim((string) (is_array($expected) ? '' : $expected));

        $hit = $this->mode === 'contains'
            ? $expectedText !== '' && Str::contains($actualText, $expectedText, ignoreCase: true)
            : $actualText === $expectedText;

        return new Score($this->name, $hit ? 1.0 : 0.0, [
            'mode' => $this->mode,
            'actual' => $actualText,
            'expected' => $expectedText,
        ]);
    }

    private function scoreOverlap(mixed $actual, mixed $expected): Score
    {
        $actualList = $this->stringList($actual);
        $expectedList = $this->stringList($expected);

        if ($expectedList === []) {
            return new Score($this->name, 1.0, ['mode' => 'overlap', 'note' => 'no expected values']);
        }

        $matched = array_values(array_intersect($expectedList, $actualList));
        $union = count(array_unique([...$expectedList, ...$actualList]));

        return new Score($this->name, count($matched) / $union, [
            'mode' => 'overlap',
            'expected' => $expectedList,
            'actual' => $actualList,
            'matched' => $matched,
            'missing' => array_values(array_diff($expectedList, $actualList)),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn (mixed $item): bool => is_string($item))
            ->unique()
            ->values()
            ->all();
    }
}

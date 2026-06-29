<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

/**
 * A deterministic guard that an output field's size sits within [min, max].
 * `mode` chooses what to measure: `words` or `chars` of a string field, or
 * `items` of an array field. A null bound is open-ended on that side.
 */
final class RangeScorer implements Scorer
{
    public function __construct(
        private string $name,
        private string $field,
        private string $mode = 'words',
        private ?int $min = null,
        private ?int $max = null,
    ) {}

    public function score(EvalSubject $subject): Score
    {
        $count = $this->measure($subject->output[$this->field] ?? null);

        $withinBounds = ($this->min === null || $count >= $this->min)
            && ($this->max === null || $count <= $this->max);

        return new Score($this->name, $withinBounds ? 1.0 : 0.0, [
            'count' => $count,
            'mode' => $this->mode,
            'min' => $this->min,
            'max' => $this->max,
        ]);
    }

    private function measure(mixed $value): int
    {
        if ($this->mode === 'items') {
            return is_array($value) ? count($value) : 0;
        }

        $text = is_array($value) ? '' : trim((string) $value);

        if ($text === '') {
            return 0;
        }

        if ($this->mode === 'chars') {
            return mb_strlen($text);
        }

        return count(preg_split('/\s+/', $text) ?: []);
    }
}

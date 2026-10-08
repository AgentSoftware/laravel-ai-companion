<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use InvalidArgumentException;

final readonly class ExpectedAnswer
{
    /**
     * @param  array<int, string>  $acceptable  Other choices that also count as right for a choice question
     */
    public function __construct(
        public bool|string $answer,
        public ?ExpectedAnswerTag $tag = null,
        public array $acceptable = [],
    ) {}

    /**
     * Read a dataset row's expected answers, keyed by question. Each value is a
     * bare answer (`true`, `"urgent"`) or an object such as
     * `{"answer": true, "tag": "must_catch"}` or
     * `{"answer": "urgent", "acceptable": ["routine"]}`. A malformed answer or an
     * unknown tag throws rather than silently dropping a must-catch gate.
     *
     * @param  array<string, mixed>  $expected
     * @return array<string, self>
     */
    public static function fromDataset(array $expected): array
    {
        return array_map(function (mixed $value): self {
            $answer = is_array($value) ? ($value['answer'] ?? null) : $value;

            if (! is_bool($answer) && ! is_string($answer)) {
                throw new InvalidArgumentException('An expected answer must be true, false or a choice, or {"answer": …, "tag": …}; got '.json_encode($value).'.');
            }

            $acceptable = is_array($value) ? ($value['acceptable'] ?? []) : [];

            $choices = is_array($acceptable) && array_is_list($acceptable) && array_filter($acceptable, 'is_string') === $acceptable;

            if (! $choices || ($acceptable !== [] && is_bool($answer))) {
                throw new InvalidArgumentException('Acceptable answers must be a list of choices for a choice question; got '.json_encode($value).'.');
            }

            $tag = is_array($value) && isset($value['tag']) ? ExpectedAnswerTag::from($value['tag']) : null;

            return new self($answer, $tag, $acceptable);
        }, $expected);
    }

    public function accepts(string $choice): bool
    {
        return $choice === $this->answer || in_array($choice, $this->acceptable, true);
    }

    /**
     * @return array<string, bool|string|array<int, string>>
     */
    public function toArray(): array
    {
        return array_filter(
            ['answer' => $this->answer, 'tag' => $this->tag?->value, 'acceptable' => $this->acceptable],
            fn (bool|string|array|null $value): bool => $value !== null && $value !== [],
        );
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use InvalidArgumentException;

final readonly class ExpectedAnswer
{
    public function __construct(
        public bool|string $answer,
        public ?ExpectedAnswerTag $tag = null,
    ) {}

    /**
     * Read a dataset row's expected answers, keyed by question. Each value is a
     * bare answer (`true`, `"urgent"`) or `{"answer": true, "tag": "must_catch"}`.
     * A malformed answer or an unknown tag throws rather than silently dropping
     * a must-catch gate.
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

            $tag = is_array($value) && isset($value['tag']) ? ExpectedAnswerTag::from($value['tag']) : null;

            return new self($answer, $tag);
        }, $expected);
    }

    /**
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return array_filter(
            ['answer' => $this->answer, 'tag' => $this->tag?->value],
            fn (bool|string|null $value): bool => $value !== null,
        );
    }
}

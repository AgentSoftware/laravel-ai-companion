<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

final readonly class ExpectedAnswer
{
    public function __construct(
        public bool|string $answer,
        public ?ExpectedAnswerTag $tag = null,
    ) {}

    /**
     * Read a dataset row's expected answers, keyed by question. Each value is a
     * bare answer (`true`, `"urgent"`) or `{"answer": true, "tag": "must_catch"}`.
     * An unknown tag throws rather than silently dropping a must-catch gate.
     *
     * @param  array<string, mixed>  $expected
     * @return array<string, self>
     */
    public static function fromDataset(array $expected): array
    {
        return array_map(
            fn (mixed $value): self => is_array($value)
                ? new self($value['answer'], isset($value['tag']) ? ExpectedAnswerTag::from($value['tag']) : null)
                : new self($value),
            $expected,
        );
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

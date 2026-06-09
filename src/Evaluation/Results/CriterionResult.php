<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Results;

readonly class CriterionResult
{
    public function __construct(
        public string $name,
        public int $score,
        public string $feedback,
    ) {}

    /** @param array{name: string, score: int|string, feedback: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            score: (int) $data['score'],
            feedback: $data['feedback'],
        );
    }

    /** @return array{name: string, score: int, feedback: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'score' => $this->score,
            'feedback' => $this->feedback,
        ];
    }
}

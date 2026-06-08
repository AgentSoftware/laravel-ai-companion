<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Results;

readonly class EvaluationResult
{
    /** @param list<CriterionResult> $criteria */
    public function __construct(
        public int $overallScore,
        public array $criteria,
        public string $summary,
        public string $judgeModel,
    ) {}

    /**
     * @param array{overall_score: int|string, criteria: list<array{name: string, score: int|string, feedback: string}>, summary: string} $data
     */
    public static function fromArray(array $data, string $judgeModel): self
    {
        return new self(
            overallScore: (int) $data['overall_score'],
            criteria: array_map(
                static fn (array $c): CriterionResult => CriterionResult::fromArray($c),
                $data['criteria'],
            ),
            summary: $data['summary'],
            judgeModel: $judgeModel,
        );
    }
}

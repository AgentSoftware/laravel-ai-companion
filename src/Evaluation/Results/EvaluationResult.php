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
     * @param  array{criteria: list<array{name: string, score: int|string, feedback: string}>, summary: string}  $data
     */
    public static function fromArray(array $data, string $judgeModel): self
    {
        $criteria = array_map(
            static fn (array $c): CriterionResult => CriterionResult::fromArray($c),
            $data['criteria'],
        );

        $overallScore = count($criteria) > 0
            ? (int) round(array_sum(array_map(fn (CriterionResult $c) => $c->score, $criteria)) / count($criteria))
            : 0;

        return new self(
            overallScore: $overallScore,
            criteria: $criteria,
            summary: $data['summary'],
            judgeModel: $judgeModel,
        );
    }
}

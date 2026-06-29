<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;

final readonly class Evaluator
{
    /**
     * @param  array<int, Scorer>  $scorers
     */
    public function __construct(private array $scorers) {}

    /**
     * @return array<int, Score>
     */
    public function evaluate(EvalSubject $subject): array
    {
        return array_map(
            fn (Scorer $scorer): Score => $scorer->score($subject),
            $this->scorers,
        );
    }
}

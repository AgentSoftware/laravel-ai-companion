<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

readonly class RowEvaluationResult
{
    public function __construct(
        public ?ExperimentEventData $event,
        public ?string $failure,
    ) {}
}

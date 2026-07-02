<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

final readonly class ScorerEntry
{
    /** @param array<int, class-string> $imports */
    public function __construct(
        public string $code,
        public array $imports = [],
    ) {}
}

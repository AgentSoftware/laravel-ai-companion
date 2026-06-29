<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

final readonly class EvalSubject
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public array $output,
        public ?object $context = null,
        public array $input = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use Laravel\Ai\Responses\Data\Answer;

final readonly class EvalSubject
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @param  array<string, Answer>  $answers  A classification row's answers, keyed by question.
     * @param  array<string, ExpectedAnswer>  $expectedAnswers  A classification row's expected answers, keyed by question.
     */
    public function __construct(
        public array $output,
        public ?object $context = null,
        public array $input = [],
        public array $answers = [],
        public array $expectedAnswers = [],
    ) {}
}

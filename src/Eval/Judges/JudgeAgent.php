<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Judges;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Generic LLM-as-judge: rates a candidate output against a reference and a
 * rubric on a 0–scale integer scale. Defaults to the cheapest Anthropic model —
 * the call is simple and runs once per dataset row — and is deterministic
 * (temperature 0). Override the provider/model via config or the prompt call.
 *
 * The schema is deliberately flat (two scalar fields, no nested objects) to stay
 * clear of provider quirks around nested `additionalProperties`.
 */
#[Provider([Lab::Anthropic])]
#[UseCheapestModel]
#[Temperature(0.0)]
final class JudgeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly string $rubric,
        private readonly string $reference,
        private readonly int $scale,
    ) {}

    public function instructions(): string
    {
        return <<<PROMPT
            You are an impartial judge scoring an AI-generated output. Rate the
            candidate from 0 to {$this->scale} against the rubric below, judging only
            what the rubric describes.

            The reference the output was generated from:
            {$this->reference}

            Rubric:
            {$this->rubric}
            PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rating' => $schema->integer()
                ->description("How well the candidate meets the rubric, 0 (poor) to {$this->scale} (excellent).")
                ->required(),
            'reasoning' => $schema->string()
                ->description('One sentence explaining the rating.')
                ->required(),
        ];
    }
}

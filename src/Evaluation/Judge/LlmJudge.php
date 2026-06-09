<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Judge;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class LlmJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $criteriaPrompt) {}

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
        You are an expert AI quality evaluator. Assess agent responses honestly and critically — do not be lenient.
        A score above 85 should be genuinely excellent. A score below 50 indicates serious problems.

        {$this->criteriaPrompt}

        Score each criterion from 0 to 100. Provide one sentence of specific, actionable feedback per criterion.
        INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'criteria' => $schema->array()
                ->items(
                    $schema->object([
                        'name' => $schema->string()->required(),
                        'score' => $schema->integer()->required(),
                        'feedback' => $schema->string()->required(),
                    ])
                )
                ->required(),
            'summary' => $schema->string()
                ->description('2–3 sentence assessment of overall response quality')
                ->required(),
        ];
    }
}

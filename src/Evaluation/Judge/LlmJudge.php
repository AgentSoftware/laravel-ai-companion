<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Judge;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\View;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class LlmJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $criteriaPrompt) {}

    public function instructions(): string
    {
        return trim(View::make('ai-companion::prompts.llm-judge', [
            'criteriaPrompt' => $this->criteriaPrompt,
        ])->render());
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

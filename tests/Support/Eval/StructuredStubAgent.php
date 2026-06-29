<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider([Lab::Anthropic])]
#[UseCheapestModel]
class StructuredStubAgent implements Agent, HasLoggableProperties, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'stub';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->required()];
    }

    /**
     * @return array<string, mixed>
     */
    public function loggableProperties(): array
    {
        return ['prompt_name' => 'stub', 'prompt_version' => 2];
    }
}

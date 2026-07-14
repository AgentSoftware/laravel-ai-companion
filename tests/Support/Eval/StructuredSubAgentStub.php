<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * A delegated sub-agent carrying a typed input, used to prove a sub-agent fake
 * receives the live instance (and can read that input) rather than a bare string.
 */
#[Provider([Lab::Anthropic])]
#[UseCheapestModel]
class StructuredSubAgentStub implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $value) {}

    public function value(): string
    {
        return $this->value;
    }

    public function instructions(): string
    {
        return 'stub sub-agent';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['echo' => $schema->string()->required()];
    }
}

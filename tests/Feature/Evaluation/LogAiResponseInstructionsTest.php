<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Middleware\LogAiResponse;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

function makeInstructionsAgent(string $instructions = 'You are a helpful agent.'): Agent
{
    return new class($instructions) implements Agent
    {
        public function __construct(private readonly string $agentInstructions) {}

        public function instructions(): string { return $this->agentInstructions; }

        public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcast(string $prompt, \Illuminate\Broadcasting\Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcastNow(string $prompt, \Illuminate\Broadcasting\Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcastOnQueue(string $prompt, \Illuminate\Broadcasting\Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }
    };
}

function makeInstructionsPrompt(string $instructions = 'You are a helpful agent.'): AgentPrompt
{
    return new AgentPrompt(
        agent: makeInstructionsAgent($instructions),
        prompt: 'Hello',
        attachments: [],
        provider: Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
    );
}

it('stores agent instructions in the log', function (): void {
    $middleware = new LogAiResponse;
    $prompt = makeInstructionsPrompt('You are a content writer for estate agents.');

    $middleware->handle($prompt, fn () => new AgentResponse(
        invocationId: 'inv-1',
        text: 'Here is your content.',
        usage: new Usage(10, 5, 0, 0),
        meta: new Meta,
    ));

    expect(AiResponseLog::first()->instructions)
        ->toBe('You are a content writer for estate agents.');
});

it('stores null instructions when agent returns empty string', function (): void {
    $middleware = new LogAiResponse;
    $prompt = makeInstructionsPrompt('');

    $middleware->handle($prompt, fn () => new AgentResponse(
        invocationId: 'inv-2',
        text: 'ok',
        usage: new Usage(10, 5, 0, 0),
        meta: new Meta,
    ));

    // Empty string instructions are converted to null via ?: null,
    // ensuring null consistently means "instructions not captured".
    expect(AiResponseLog::first()->instructions)->toBeNull();
});

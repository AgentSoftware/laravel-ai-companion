<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tests\TestCase;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

uses(TestCase::class)->in('Feature');

function makeTracingAgent(): Agent
{
    return new class implements Agent
    {
        public function instructions(): string
        {
            return 'You are a test agent.';
        }

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

        public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }
    };
}

function makeTracingPromptedEvent(string $invocationId = 'inv-1'): AgentPrompted
{
    $response = new AgentResponse(
        invocationId: $invocationId,
        text: 'World',
        usage: new Usage(
            promptTokens: 100,
            completionTokens: 50,
            cacheWriteInputTokens: 10,
            cacheReadInputTokens: 5,
        ),
        meta: new Meta(provider: 'anthropic', model: 'claude-haiku-4-5-20251001'),
    );

    $prompt = new AgentPrompt(
        agent: makeTracingAgent(),
        prompt: 'Hello',
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
        invocationId: $invocationId,
    );

    return new AgentPrompted(invocationId: $invocationId, prompt: $prompt, response: $response);
}

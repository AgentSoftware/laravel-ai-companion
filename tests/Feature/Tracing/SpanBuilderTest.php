<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Tools\Request as ToolRequest;

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

afterEach(function () {
    Context::forget('ai_usage_source_id');
    Context::forget('ai_usage_source_model');
});

it('builds an agent span without source context as its own trace root', function () {
    $span = app(SpanBuilder::class)->agentSpan(makeTracingPromptedEvent(), 100.0, 103.5);

    expect($span['id'])->toBe('inv-1')
        ->and($span['trace_id'])->toBe('inv-1')
        ->and($span['parent_id'])->toBeNull()
        ->and($span['type'])->toBe('llm')
        ->and($span['input'])->toBe(['prompt' => 'Hello', 'instructions' => 'You are a test agent.'])
        ->and($span['output'])->toBe('World')
        ->and($span['error'])->toBeNull()
        ->and($span['metadata']['model'])->toBe('claude-haiku-4-5-20251001')
        ->and($span['metadata']['provider'])->toBe('anthropic')
        ->and($span['metrics'])->toBe([
            'start' => 100.0,
            'end' => 103.5,
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'tokens' => 150,
            'cache_write_tokens' => 10,
            'cache_read_tokens' => 5,
            'reasoning_tokens' => 0,
        ]);
});

it('parents agent spans under a deterministic root when source context is set', function () {
    Context::add('ai_usage_source_id', 'session-9');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    $builder = app(SpanBuilder::class);
    $span = $builder->agentSpan(makeTracingPromptedEvent(), 100.0, 101.0);
    $root = $builder->rootSpan();

    expect($root)->not->toBeNull()
        ->and($span['parent_id'])->toBe($root['id'])
        ->and($span['trace_id'])->toBe($root['id'])
        ->and($root['trace_id'])->toBe($root['id'])
        ->and($root['parent_id'])->toBeNull()
        ->and($root['name'])->toBe('OnboardingSession')
        ->and($root['type'])->toBe('task')
        ->and($root['metadata']['source_id'])->toBe('session-9');

    // Deterministic: same source always produces the same root id.
    expect($builder->rootSpan()['id'])->toBe($root['id']);
});

it('returns no root span without source context', function () {
    expect(app(SpanBuilder::class)->rootSpan())->toBeNull();
});

it('attaches failover metadata to agent spans', function () {
    $failovers = [['provider' => 'OpenAi', 'model' => 'gpt-4.1', 'error' => 'rate limited']];

    $span = app(SpanBuilder::class)->agentSpan(makeTracingPromptedEvent(), 100.0, 101.0, $failovers);

    expect($span['metadata']['failovers'])->toBe($failovers);
});

it('builds a tool span parented to its agent invocation', function () {
    Context::add('ai_usage_source_id', 'session-9');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'A test tool.';
        }

        public function handle(ToolRequest $request): string
        {
            return 'result';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $event = new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-7',
        agent: makeTracingAgent(),
        tool: $tool,
        arguments: ['query' => 'homes'],
        result: 'found 3',
    );

    $builder = app(SpanBuilder::class);
    $span = $builder->toolSpan($event, 100.0, 100.4);

    expect($span['id'])->toBe('tool-7')
        ->and($span['parent_id'])->toBe('inv-1')
        ->and($span['trace_id'])->toBe($builder->rootSpan()['id'])
        ->and($span['type'])->toBe('tool')
        ->and($span['input'])->toBe(['query' => 'homes'])
        ->and($span['output'])->toBe('found 3')
        ->and($span['metrics']['start'])->toBe(100.0)
        ->and($span['metrics']['end'])->toBe(100.4);
});

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Tools\Request as ToolRequest;

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

it('falls back to the prompt model when response meta has no provider or model', function () {
    $response = new AgentResponse(
        invocationId: 'inv-2',
        text: 'World',
        usage: new Usage(
            promptTokens: 100,
            completionTokens: 50,
            cacheWriteInputTokens: 10,
            cacheReadInputTokens: 5,
        ),
        meta: new Meta,
    );

    $prompt = new AgentPrompt(
        agent: makeTracingAgent(),
        prompt: 'Hello',
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
        invocationId: 'inv-2',
    );

    $event = new AgentPrompted(invocationId: 'inv-2', prompt: $prompt, response: $response);
    $span = app(SpanBuilder::class)->agentSpan($event, 100.0, 101.0);

    expect($span['metadata']['model'])->toBe('claude-haiku-4-5-20251001')
        ->and($span['metadata'])->not->toHaveKey('provider');
});

it('merges the agent loggable properties into span metadata', function () {
    $agent = new class extends StubAgent implements HasLoggableProperties
    {
        public function loggableProperties(): array
        {
            return [
                'content_item_id' => 'ci-1',
                'campaign_id' => 'camp-1',
                'user_id' => 'user-1',
                'prompt_version' => 3,
                'agent_group' => 'email-builder',
                'focus_block_id' => null,
            ];
        }
    };

    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Hello',
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
        invocationId: 'inv-9',
    );

    $event = new AgentPrompted(invocationId: 'inv-9', prompt: $prompt, response: new AgentResponse(
        invocationId: 'inv-9',
        text: 'World',
        usage: new Usage(promptTokens: 1, completionTokens: 1, cacheWriteInputTokens: 0, cacheReadInputTokens: 0),
        meta: new Meta(provider: 'anthropic', model: 'claude-haiku-4-5-20251001'),
    ));

    $span = app(SpanBuilder::class)->agentSpan($event, 100.0, 101.0);

    expect($span['metadata']['content_item_id'])->toBe('ci-1')
        ->and($span['metadata']['campaign_id'])->toBe('camp-1')
        ->and($span['metadata']['user_id'])->toBe('user-1')
        ->and($span['metadata']['prompt_version'])->toBe(3)
        ->and($span['metadata']['agent_group'])->toBe('email-builder')
        ->and($span['metadata'])->not->toHaveKey('focus_block_id')
        ->and($span['metadata']['agent'])->toBe($agent::class);
});

it('uses the structured array as span output for a StructuredAgentResponse', function () {
    $structured = ['name' => 'Elliot', 'score' => 42];

    $response = new StructuredAgentResponse(
        invocationId: 'inv-3',
        structured: $structured,
        text: '{"name":"Elliot","score":42}',
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
        invocationId: 'inv-3',
    );

    $event = new AgentPrompted(invocationId: 'inv-3', prompt: $prompt, response: $response);
    $span = app(SpanBuilder::class)->agentSpan($event, 100.0, 101.0);

    expect($span['output'])->toBe($structured);
});

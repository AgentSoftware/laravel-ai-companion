<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Middleware\LogAiResponse;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

function makeMiddlewareAgent(?array $loggableProperties = null): Agent
{
    if ($loggableProperties === null) {
        return new class implements Agent
        {
            public function instructions(): string
            {
                return 'instructions';
            }

            public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): AgentResponse
            {
                throw new RuntimeException('Not implemented');
            }

            public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
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

    return new class($loggableProperties) implements Agent, HasLoggableProperties
    {
        public function __construct(private readonly array $properties) {}

        public function loggableProperties(): array
        {
            return $this->properties;
        }

        public function instructions(): string
        {
            return 'instructions';
        }

        public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): AgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
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

function makeMiddlewarePrompt(string $promptText = 'Hello', ?array $loggableProperties = null): AgentPrompt
{
    return new AgentPrompt(
        agent: makeMiddlewareAgent($loggableProperties),
        prompt: $promptText,
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
    );
}

function makeUsage(): Usage
{
    return new Usage(
        promptTokens: 10,
        completionTokens: 5,
        cacheWriteInputTokens: 0,
        cacheReadInputTokens: 0,
    );
}

it('logs a successful text response', function () {
    $middleware = new LogAiResponse;
    $prompt = makeMiddlewarePrompt(promptText: 'Hello');

    $response = $middleware->handle($prompt, fn () => new AgentResponse(
        invocationId: 'inv-1',
        text: 'Hi there',
        usage: makeUsage(),
        meta: new Meta(provider: 'anthropic', model: 'claude-haiku-4-5-20251001'),
    ));

    expect($response)->toBeInstanceOf(AgentResponse::class);

    $log = AiResponseLog::first();
    expect($log->invocation_id)->toBe('inv-1')
        ->and($log->prompt)->toBe('Hello')
        ->and($log->response)->toBe(['text' => 'Hi there'])
        ->and($log->status)->toBe(AiResponseStatus::Success)
        ->and($log->metadata)->toMatchArray(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001'])
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('stores structured response payloads as JSON', function () {
    $middleware = new LogAiResponse;
    $prompt = makeMiddlewarePrompt();

    $middleware->handle($prompt, fn () => new StructuredAgentResponse(
        invocationId: 'inv-2',
        structured: ['result' => 'ok', 'items' => [1, 2, 3]],
        text: '{"result":"ok"}',
        usage: makeUsage(),
        meta: new Meta,
    ));

    $log = AiResponseLog::first();
    expect($log->response)->toBe(['result' => 'ok', 'items' => [1, 2, 3]]);
});

it('records loggable properties when the agent implements the contract', function () {
    $middleware = new LogAiResponse;
    $prompt = makeMiddlewarePrompt(loggableProperties: ['company_id' => 42, 'user_id' => 7]);

    $middleware->handle($prompt, fn () => new AgentResponse(
        invocationId: 'inv-3',
        text: 'ok',
        usage: makeUsage(),
        meta: new Meta,
    ));

    $log = AiResponseLog::first();
    expect($log->properties)->toBe(['company_id' => 42, 'user_id' => 7]);
});

it('marks the row as failed and rethrows when the agent throws', function () {
    $middleware = new LogAiResponse;
    $prompt = makeMiddlewarePrompt();

    expect(fn () => $middleware->handle($prompt, function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    $log = AiResponseLog::first();
    expect($log->status)->toBe(AiResponseStatus::Failure)
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($log->invocation_id)->toBeNull();
});

it('writes a running row before the agent returns', function () {
    $middleware = new LogAiResponse;
    $prompt = makeMiddlewarePrompt(promptText: 'check status mid-flight');

    $middleware->handle($prompt, function () {
        $inflight = AiResponseLog::first();
        expect($inflight->status)->toBe(AiResponseStatus::Running)
            ->and($inflight->prompt)->toBe('check status mid-flight');

        return new AgentResponse(
            invocationId: 'inv-4',
            text: 'done',
            usage: makeUsage(),
            meta: new Meta,
        );
    });

    expect(AiResponseLog::first()->status)->toBe(AiResponseStatus::Success);
});

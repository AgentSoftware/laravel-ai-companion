<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;
use AgentSoftware\LaravelAiCompanion\Tests\TestCase;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(TestCase::class)->in('Feature');

function makeTracingAgent(): Agent
{
    return new StubAgent;
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

function makeToolInvoked(
    string $invocationId = 'inv-1',
    string $toolInvocationId = 'tool-1',
    ?Agent $agent = null,
    ?Tool $tool = null,
    array $arguments = [],
    mixed $result = 'ok',
    float $time = 1.5,
): ToolInvoked {
    $args = [
        'invocationId' => $invocationId,
        'toolInvocationId' => $toolInvocationId,
        'agent' => $agent ?? makeTracingAgent(),
        'tool' => $tool ?? Mockery::mock(Tool::class),
        'arguments' => $arguments,
        'result' => $result,
        'time' => $time,
    ];

    // laravel/ai 0.11 added $time.
    if (! property_exists(ToolInvoked::class, 'time')) {
        unset($args['time']);
    }

    return new ToolInvoked(...$args);
}

function makeAgentFailedOver(
    ?Agent $agent = null,
    string $model = 'gpt-4.1',
    ?Provider $provider = null,
    ?FailoverableException $exception = null,
    string $invocationId = 'inv-failover',
): AgentFailedOver {
    $args = [
        'invocationId' => $invocationId,
        'agent' => $agent ?? makeTracingAgent(),
        'provider' => $provider ?? Mockery::mock(Provider::class),
        'model' => $model,
        'exception' => $exception ?? new RateLimitedException('rate limited'),
    ];

    // laravel/ai 0.11 added $invocationId.
    if (! property_exists(AgentFailedOver::class, 'invocationId')) {
        unset($args['invocationId']);
    }

    return new AgentFailedOver(...$args);
}

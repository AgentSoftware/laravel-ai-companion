<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;
use AgentSoftware\LaravelAiCompanion\Tests\TestCase;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
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

/**
 * Construct an SDK event with only the constructor parameters the installed
 * laravel/ai version declares. 0.11 added ToolInvoked::$time and
 * AgentFailedOver::$invocationId; on 0.7/0.8 those are silently dropped so the
 * same test runs green against every supported SDK.
 *
 * @param  class-string  $event
 * @param  array<string, mixed>  $args
 */
function buildAiEvent(string $event, array $args): object
{
    $declared = array_column(
        (new ReflectionMethod($event, '__construct'))->getParameters(),
        'name',
    );

    return new $event(...array_intersect_key($args, array_flip($declared)));
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
    return buildAiEvent(ToolInvoked::class, [
        'invocationId' => $invocationId,
        'toolInvocationId' => $toolInvocationId,
        'agent' => $agent ?? makeTracingAgent(),
        'tool' => $tool ?? Mockery::mock(Tool::class),
        'arguments' => $arguments,
        'result' => $result,
        'time' => $time,
    ]);
}

/**
 * 0.11's step-native gateway reports each step's tool calls; 0.7/0.8 do not, so
 * first-step tool calls come back empty there. Tests assert the value the
 * installed SDK can actually produce.
 */
function sdkReportsStepToolCalls(): bool
{
    return interface_exists(StepTextGateway::class);
}

function makeAgentFailedOver(
    ?Agent $agent = null,
    string $model = 'gpt-4.1',
    ?Provider $provider = null,
    ?FailoverableException $exception = null,
    string $invocationId = 'inv-failover',
): AgentFailedOver {
    return buildAiEvent(AgentFailedOver::class, [
        'invocationId' => $invocationId,
        'agent' => $agent ?? makeTracingAgent(),
        'provider' => $provider ?? Mockery::mock(Provider::class),
        'model' => $model,
        'exception' => $exception ?? new RateLimitedException('rate limited'),
    ]);
}

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;
use AgentSoftware\LaravelAiCompanion\Tests\TestCase;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
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

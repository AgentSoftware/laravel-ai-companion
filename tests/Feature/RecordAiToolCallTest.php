<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Listeners\RecordAiToolCall;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Models\AiToolCall;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

function subscribeToolCallLogging(): void
{
    config()->set('ai-companion.tool_call_logs.enabled', true);

    Event::subscribe(RecordAiToolCall::class);
}

it('records a tool call linked to its response log', function () {
    subscribeToolCallLogging();

    $log = AiResponseLog::create([
        'invocation_id' => 'inv-1',
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    event(new InvokingTool(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-1',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: ['q' => 'x'],
    ));
    event(new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-1',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: ['q' => 'x'],
        result: 'ok',
    ));

    expect(AiToolCall::count())->toBe(1);

    $call = AiToolCall::first();
    expect($call->ai_response_log_id)->toBe($log->id)
        ->and($call->tool_invocation_id)->toBe('tool-1')
        ->and($call->input)->toBe(['q' => 'x'])
        ->and($call->output)->toBe('ok')
        ->and($call->duration_ms)->toBeInt();
});

it('skips silently when no matching response log exists', function () {
    subscribeToolCallLogging();

    event(new ToolInvoked(
        invocationId: 'inv-missing',
        toolInvocationId: 'tool-missing',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: [],
        result: null,
    ));

    expect(AiToolCall::count())->toBe(0);
});

it('never throws when tool call recording fails', function () {
    subscribeToolCallLogging();

    $log = AiResponseLog::create([
        'invocation_id' => 'inv-x',
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    // Pre-existing row with the same tool_invocation_id trips the unique
    // constraint, forcing the listener's create() to throw internally.
    AiToolCall::create([
        'ai_response_log_id' => $log->id,
        'tool_invocation_id' => 'tool-dupe',
        'tool' => 'App\\Tools\\SearchTool',
        'input' => [],
    ]);

    event(new ToolInvoked(
        invocationId: 'inv-x',
        toolInvocationId: 'tool-dupe',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: ['q' => 'y'],
        result: 'ok',
    ));

    expect(AiToolCall::count())->toBe(1);
});

it('does not record tool calls when the feature flag is disabled', function () {
    AiResponseLog::create([
        'invocation_id' => 'inv-disabled',
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    event(new ToolInvoked(
        invocationId: 'inv-disabled',
        toolInvocationId: 'tool-disabled',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: [],
        result: 'ok',
    ));

    expect(AiToolCall::count())->toBe(0);
});

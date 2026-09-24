<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Listeners\RecordAiToolCall;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Models\AiToolCall;
use AgentSoftware\LaravelAiCompanion\PendingAiResponseLogs;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;

function subscribeToolCallLogging(): void
{
    config()->set('ai-companion.tool_call_logs.enabled', true);

    Event::subscribe(RecordAiToolCall::class);
}

it('records a tool call linked to its response log', function () {
    subscribeToolCallLogging();

    $agent = makeTracingAgent();

    $log = AiResponseLog::create([
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Running,
    ]);

    app(PendingAiResponseLogs::class)->put($agent, $log->id);

    event(new InvokingTool(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-1',
        agent: $agent,
        tool: Mockery::mock(Tool::class),
        arguments: ['q' => 'x'],
    ));
    event(makeToolInvoked(
        toolInvocationId: 'tool-1',
        agent: $agent,
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

    event(makeToolInvoked(
        invocationId: 'inv-missing',
        toolInvocationId: 'tool-missing',
        result: null,
    ));

    expect(AiToolCall::count())->toBe(0);
});

it('never throws when tool call recording fails', function () {
    subscribeToolCallLogging();

    $agent = makeTracingAgent();

    $log = AiResponseLog::create([
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Running,
    ]);

    app(PendingAiResponseLogs::class)->put($agent, $log->id);

    // Pre-existing row with the same tool_invocation_id trips the unique
    // constraint, forcing the listener's create() to throw internally.
    AiToolCall::create([
        'ai_response_log_id' => $log->id,
        'tool_invocation_id' => 'tool-dupe',
        'tool' => 'App\\Tools\\SearchTool',
        'input' => [],
    ]);

    event(makeToolInvoked(
        invocationId: 'inv-x',
        toolInvocationId: 'tool-dupe',
        agent: $agent,
        arguments: ['q' => 'y'],
        result: 'ok',
    ));

    expect(AiToolCall::count())->toBe(1);
});

it('does not record tool calls when the feature flag is disabled', function () {
    $agent = makeTracingAgent();

    $log = AiResponseLog::create([
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Running,
    ]);

    app(PendingAiResponseLogs::class)->put($agent, $log->id);

    event(makeToolInvoked(
        invocationId: 'inv-disabled',
        toolInvocationId: 'tool-disabled',
        agent: $agent,
    ));

    expect(AiToolCall::count())->toBe(0);
});

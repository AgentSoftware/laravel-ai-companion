<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Models\AiToolCall;

it('belongs to a response log and casts input/output to arrays', function () {
    $log = AiResponseLog::create([
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    $call = AiToolCall::create([
        'ai_response_log_id' => $log->id,
        'tool_invocation_id' => 'tool-1',
        'tool' => 'App\\Tools\\SearchTool',
        'input' => ['query' => 'x'],
        'output' => ['results' => []],
        'duration_ms' => 42,
    ]);

    expect($call->input)->toBe(['query' => 'x'])
        ->and($call->output)->toBe(['results' => []])
        ->and($call->responseLog->is($log))->toBeTrue()
        ->and($log->fresh()->toolCalls->first()->is($call))->toBeTrue();
});

it('cascades delete from the parent response log', function () {
    $log = AiResponseLog::create([
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    AiToolCall::create([
        'ai_response_log_id' => $log->id,
        'tool' => 'App\\Tools\\SearchTool',
        'input' => [],
    ]);

    $log->delete();

    expect(AiToolCall::count())->toBe(0);
});

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use AgentSoftware\LaravelAiCompanion\Tracing\Listeners\ExportTrace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamedAgentResponse;

function subscribeTracingListeners(): void
{
    config()->set('ai-companion.braintrust.enabled', true);
    config()->set('ai-companion.braintrust.api_key', 'test-key');

    Event::subscribe(ExportTrace::class);
}

afterEach(function () {
    Context::forget('ai_usage_source_id');
    Context::forget('ai_usage_source_model');
});

it('ships an agent span with timing when a prompt completes', function () {
    subscribeTracingListeners();
    Queue::fake();

    $prompted = makeTracingPromptedEvent('inv-42');

    event(new PromptingAgent(invocationId: 'inv-42', prompt: $prompted->prompt));
    event($prompted);

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        $span = collect($job->spans)->firstWhere('id', 'inv-42');

        return $span !== null
            && $span['type'] === 'llm'
            && $span['metrics']['start'] !== null
            && $span['metrics']['end'] >= $span['metrics']['start'];
    });
});

it('includes the root span in the batch when source context is set', function () {
    subscribeTracingListeners();
    Queue::fake();

    Context::add('ai_usage_source_id', 'session-1');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    event(makeTracingPromptedEvent('inv-1'));

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        return count($job->spans) === 2
            && $job->spans[0]['type'] === 'task'
            && $job->spans[1]['parent_id'] === $job->spans[0]['id'];
    });
});

it('attaches failover details to the next span for that agent', function () {
    subscribeTracingListeners();
    Queue::fake();

    $prompted = makeTracingPromptedEvent('inv-9');

    $exception = Mockery::mock(FailoverableException::class);
    $exception->allows('getMessage')->andReturn('rate limited');

    event(new AgentFailedOver(
        agent: $prompted->prompt->agent,
        provider: Mockery::mock(Provider::class),
        model: 'gpt-4.1',
        exception: $exception,
    ));
    event($prompted);

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        $span = collect($job->spans)->firstWhere('id', 'inv-9');

        return $span['metadata']['failovers'][0]['model'] === 'gpt-4.1';
    });
});

it('does not ship anything for streamed responses', function () {
    subscribeTracingListeners();
    Queue::fake();

    $prompted = makeTracingPromptedEvent('inv-stream');
    $streamed = new AgentPrompted(
        invocationId: 'inv-stream',
        prompt: $prompted->prompt,
        response: new StreamedAgentResponse('inv-stream', new Collection, new Meta),
    );

    event($streamed);

    Queue::assertNothingPushed();
});

it('never throws even when span building fails', function () {
    subscribeTracingListeners();
    Queue::fake();

    event(new ToolInvoked(
        invocationId: 'inv-x',
        toolInvocationId: 'tool-x',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: [],
        result: fopen('php://memory', 'r'), // non-JSON-serializable: must be swallowed, not thrown
    ));

    Queue::assertNothingPushed();
});

it('also ships tool spans', function () {
    subscribeTracingListeners();
    Queue::fake();

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

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        $span = collect($job->spans)->firstWhere('id', 'tool-1');

        return $span !== null
            && $span['type'] === 'tool'
            && $span['metrics']['start'] !== null;
    });
});

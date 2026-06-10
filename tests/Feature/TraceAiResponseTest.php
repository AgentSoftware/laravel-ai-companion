<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Middleware\TraceAiResponse;
use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;

function makeTracingFailurePrompt(?string $invocationId = null): AgentPrompt
{
    return new AgentPrompt(
        agent: makeTracingAgent(),
        prompt: 'Hello',
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
        invocationId: $invocationId,
    );
}

afterEach(function () {
    Context::forget('ai_usage_source_id');
    Context::forget('ai_usage_source_model');
});

it('ships an error span and rethrows when the agent call fails', function () {
    Queue::fake();
    Context::add('ai_usage_source_id', 'session-1');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    $middleware = app(TraceAiResponse::class);

    try {
        $middleware->handle(makeTracingFailurePrompt(), function (): never {
            throw new RuntimeException('provider exploded');
        });

        $this->fail('Exception was not rethrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('provider exploded');
    }

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        $span = collect($job->spans)->last();

        return $span['error'] === 'provider exploded'
            && $span['type'] === 'llm'
            && $span['parent_id'] !== null
            && $span['metrics']['end'] >= $span['metrics']['start'];
    });
});

it('consumes the orphaned timing entry for the failed invocation', function () {
    Queue::fake();

    $timings = app(TraceTimings::class);
    $timings->start('agent:inv-fail', 50.0);

    try {
        app(TraceAiResponse::class)->handle(makeTracingFailurePrompt('inv-fail'), function (): never {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // Entry must be consumed (no leak), and its start time used on the span.
    expect($timings->pull('agent:inv-fail'))->toBeNull();

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        return collect($job->spans)->last()['metrics']['start'] === 50.0;
    });
});

it('passes successful responses through without shipping', function () {
    Queue::fake();

    $middleware = app(TraceAiResponse::class);
    $expected = makeTracingPromptedEvent()->response;

    $result = $middleware->handle(makeTracingFailurePrompt(), fn () => $expected);

    expect($result)->toBe($expected);
    Queue::assertNothingPushed();
});

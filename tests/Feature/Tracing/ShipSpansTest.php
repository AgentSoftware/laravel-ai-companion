<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tests\Support\FakeTraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use Illuminate\Support\Facades\Log;

it('hands its spans to the bound TraceExporter', function () {
    $fake = new FakeTraceExporter;
    app()->instance(TraceExporter::class, $fake);

    $spans = [['id' => 'span-1', 'trace_id' => 'span-1']];

    (new ShipSpans($spans))->handle(app(TraceExporter::class));

    expect($fake->batches)->toBe([$spans]);
});

it('does nothing when the exporter is disabled', function () {
    $fake = new FakeTraceExporter;
    $fake->isEnabled = false;

    (new ShipSpans([['id' => 'span-1']]))->handle($fake);

    expect($fake->batches)->toBe([]);
});

it('uses the configured queue and connection', function () {
    config()->set('ai-companion.braintrust.queue.connection', 'redis');
    config()->set('ai-companion.braintrust.queue.queue', 'tracing');

    $job = new ShipSpans([]);

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('tracing');
});

it('logs a warning and drops the batch on final failure', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['spans'] === 2
            && $context['exception'] === 'boom');

    (new ShipSpans([['id' => 'a'], ['id' => 'b']]))->failed(new RuntimeException('boom'));
});

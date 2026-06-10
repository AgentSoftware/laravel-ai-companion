<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;

class FakeTraceExporter implements TraceExporter
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $batches = [];

    public bool $isEnabled = true;

    public function enabled(): bool
    {
        return $this->isEnabled;
    }

    public function ship(array $spans): void
    {
        $this->batches[] = $spans;
    }
}

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

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;

it('is a singleton', function () {
    expect(app(TraceTimings::class))->toBe(app(TraceTimings::class));
});

it('stores and pulls start times', function () {
    $timings = new TraceTimings;

    $timings->start('agent:abc', 123.45);

    expect($timings->pull('agent:abc'))->toBe(123.45)
        ->and($timings->pull('agent:abc'))->toBeNull();
});

it('returns null for unknown keys', function () {
    expect((new TraceTimings)->pull('missing'))->toBeNull();
});

it('collects and pulls pending failovers per agent class', function () {
    $timings = new TraceTimings;

    $timings->addFailover('App\Agents\Foo', ['provider' => 'OpenAi', 'model' => 'gpt-4.1', 'error' => 'boom']);
    $timings->addFailover('App\Agents\Foo', ['provider' => 'Groq', 'model' => 'llama', 'error' => 'down']);

    expect($timings->pullFailovers('App\Agents\Foo'))->toHaveCount(2)
        ->and($timings->pullFailovers('App\Agents\Foo'))->toBe([])
        ->and($timings->pullFailovers('App\Agents\Bar'))->toBe([]);
});

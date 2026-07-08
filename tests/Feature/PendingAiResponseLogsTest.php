<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\PendingAiResponseLogs;

it('is a singleton', function () {
    expect(app(PendingAiResponseLogs::class))->toBe(app(PendingAiResponseLogs::class));
});

it('stores and retrieves a log id by agent instance', function () {
    $pending = new PendingAiResponseLogs;
    $agent = makeTracingAgent();

    $pending->put($agent, 'log-1');

    expect($pending->get($agent))->toBe('log-1');
});

it('returns null for an agent with no pending log', function () {
    expect((new PendingAiResponseLogs)->get(makeTracingAgent()))->toBeNull();
});

it('does not confuse different agent instances', function () {
    $pending = new PendingAiResponseLogs;

    // Both instances must stay alive for the assertion: spl_object_id can be
    // recycled once an object is garbage collected, which would make an
    // unrelated later instance look like it shares the first one's id.
    $agentA = makeTracingAgent();
    $agentB = makeTracingAgent();

    $pending->put($agentA, 'log-1');

    expect($pending->get($agentB))->toBeNull();
});

it('forgets a stored log id', function () {
    $pending = new PendingAiResponseLogs;
    $agent = makeTracingAgent();

    $pending->put($agent, 'log-1');
    $pending->forget($agent);

    expect($pending->get($agent))->toBeNull();
});

it('caps stored entries to bound memory in long-lived workers', function () {
    $pending = new PendingAiResponseLogs;
    $agents = [];

    foreach (range(1, 501) as $i) {
        $agents[$i] = makeTracingAgent();
        $pending->put($agents[$i], "log-{$i}");
    }

    expect($pending->get($agents[1]))->toBeNull()          // evicted
        ->and($pending->get($agents[501]))->toBe('log-501'); // retained
});

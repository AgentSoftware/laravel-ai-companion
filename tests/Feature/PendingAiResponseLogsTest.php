<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\PendingAiResponseLogs;

it('is a singleton', function () {
    expect(app(PendingAiResponseLogs::class))->toBe(app(PendingAiResponseLogs::class));
});

it('stores and retrieves a log id by invocation id', function () {
    $pending = new PendingAiResponseLogs;

    $pending->put('inv-1', 'log-1');

    expect($pending->get('inv-1'))->toBe('log-1');
});

it('returns null for an invocation with no pending log', function () {
    expect((new PendingAiResponseLogs)->get('inv-unknown'))->toBeNull();
});

it('forgets a stored log id', function () {
    $pending = new PendingAiResponseLogs;

    $pending->put('inv-1', 'log-1');
    $pending->forget('inv-1');

    expect($pending->get('inv-1'))->toBeNull();
});

it('caps stored entries to bound memory in long-lived workers', function () {
    $pending = new PendingAiResponseLogs;

    foreach (range(1, 501) as $i) {
        $pending->put("inv-{$i}", "log-{$i}");
    }

    expect($pending->get('inv-1'))->toBeNull()          // evicted
        ->and($pending->get('inv-501'))->toBe('log-501'); // retained
});

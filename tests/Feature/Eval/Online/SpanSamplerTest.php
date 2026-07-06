<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Online\SpanSampler;

it('is deterministic for the same span id and rate', function (): void {
    $sampler = new SpanSampler;

    expect($sampler->selects('span-abc', 0.5))->toBe($sampler->selects('span-abc', 0.5));
});

it('selects everything at rate 1 and nothing at rate 0', function (): void {
    $sampler = new SpanSampler;

    expect($sampler->selects('span-abc', 1.0))->toBeTrue()
        ->and($sampler->selects('span-abc', 0.0))->toBeFalse();
});

it('selects roughly the requested fraction of ids', function (): void {
    $sampler = new SpanSampler;

    $selected = collect(range(1, 1000))
        ->filter(fn (int $i): bool => $sampler->selects("span-{$i}", 0.3))
        ->count();

    expect($selected)->toBeGreaterThan(200)->toBeLessThan(400);
});

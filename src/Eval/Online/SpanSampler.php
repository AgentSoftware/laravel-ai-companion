<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Online;

/**
 * Deterministic per-span sampling: hashing the span id means re-runs select
 * the same spans, so overlapping schedule runs can neither double-spend on
 * LLM-judge calls nor leave gaps.
 */
final readonly class SpanSampler
{
    public function selects(string $spanId, float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (crc32($spanId) % 10_000) / 10_000 < $rate;
    }
}

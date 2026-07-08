<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ConcurrencyRunner;
use Illuminate\Support\Facades\Concurrency;

final class LaravelConcurrencyRunner implements ConcurrencyRunner
{
    public function run(array $tasks, int $timeout): array
    {
        // Pinned explicitly: falling back to the app's concurrency.default
        // config would let a consumer's 'sync' default silently turn
        // --concurrency into a no-op.
        return Concurrency::driver('process')->run($tasks, $timeout);
    }
}

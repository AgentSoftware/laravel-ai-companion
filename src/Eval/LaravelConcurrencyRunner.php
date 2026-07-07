<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ConcurrencyRunner;
use Illuminate\Support\Facades\Concurrency;

final class LaravelConcurrencyRunner implements ConcurrencyRunner
{
    public function run(array $tasks): array
    {
        return Concurrency::run($tasks);
    }
}

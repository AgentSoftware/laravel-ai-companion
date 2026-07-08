<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

interface ConcurrencyRunner
{
    /**
     * Run a batch of tasks and return their results in the same order.
     *
     * @param  array<int, callable(): mixed>  $tasks
     * @param  int  $timeout  Seconds before a task is killed. Agent calls can run long
     *                        under concurrent load, so this should be generous.
     * @return array<int, mixed>
     */
    public function run(array $tasks, int $timeout): array;
}

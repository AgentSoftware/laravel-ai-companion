<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ConcurrencyRunner;

/**
 * Test double that runs "concurrent" tasks synchronously in-process (no real
 * forking — Http::fake/Process::fake state wouldn't cross a real subprocess
 * boundary) while recording each batch size so tests can assert chunking.
 */
class RecordingConcurrencyRunner implements ConcurrencyRunner
{
    /** @var array<int, int> */
    public static array $batchSizes = [];

    public static function reset(): void
    {
        self::$batchSizes = [];
    }

    public function run(array $tasks, int $timeout): array
    {
        self::$batchSizes[] = count($tasks);

        return array_map(fn (callable $task): mixed => $task(), $tasks);
    }
}

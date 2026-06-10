<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing;

/**
 * Timing entries are best-effort; the cap bounds memory in long-lived workers
 * because hard failures can orphan entries that are never pulled.
 */
class TraceTimings
{
    private const int MAX_ENTRIES = 500;

    /** @var array<string, float> */
    private array $startTimes = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $failovers = [];

    public function start(string $key, float $time): void
    {
        if (count($this->startTimes) >= self::MAX_ENTRIES) {
            array_shift($this->startTimes);
        }

        $this->startTimes[$key] = $time;
    }

    public function pull(string $key): ?float
    {
        $time = $this->startTimes[$key] ?? null;

        unset($this->startTimes[$key]);

        return $time;
    }

    /**
     * @param  array<string, mixed>  $failover
     */
    public function addFailover(string $agentClass, array $failover): void
    {
        if (count($this->failovers) >= self::MAX_ENTRIES) {
            array_shift($this->failovers);
        }

        $this->failovers[$agentClass][] = $failover;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pullFailovers(string $agentClass): array
    {
        $failovers = $this->failovers[$agentClass] ?? [];

        unset($this->failovers[$agentClass]);

        return $failovers;
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing;

class TraceTimings
{
    /** @var array<string, float> */
    private array $startTimes = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $failovers = [];

    public function start(string $key, float $time): void
    {
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

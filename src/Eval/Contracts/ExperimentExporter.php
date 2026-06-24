<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

interface ExperimentExporter
{
    /**
     * Whether this exporter is configured and able to push experiments.
     */
    public function enabled(): bool;

    /**
     * Create (or reuse) a named experiment and insert scored events into it.
     *
     * Returns the backend experiment id.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<string, mixed>  $metadata  Experiment-level metadata (e.g. a catalogue snapshot).
     * @param  array<string, mixed>  $repoInfo  Git metadata (branch, commit, …) so the backend can auto-select a baseline.
     */
    public function export(string $experiment, array $events, array $metadata = [], array $repoInfo = []): string;
}

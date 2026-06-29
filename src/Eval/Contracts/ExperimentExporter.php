<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

use AgentSoftware\LaravelAiCompanion\Eval\ExperimentEventData;
use AgentSoftware\LaravelAiCompanion\Eval\RepoInfo;

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
     * @param  array<int, ExperimentEventData>  $events
     * @param  array<string, mixed>  $metadata  Experiment-level metadata (e.g. a catalogue snapshot).
     * @param  RepoInfo|null  $repoInfo  Git metadata so the backend can auto-select a baseline.
     */
    public function export(string $experiment, array $events, array $metadata = [], ?RepoInfo $repoInfo = null): string;
}

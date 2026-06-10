<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Contracts;

interface TraceExporter
{
    /**
     * Whether this exporter is configured and able to ship spans.
     */
    public function enabled(): bool;

    /**
     * Ship a batch of neutral span arrays to the tracing backend.
     *
     * @param  array<int, array<string, mixed>>  $spans
     */
    public function ship(array $spans): void;
}

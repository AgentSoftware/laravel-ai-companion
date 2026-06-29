<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Exporters;

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use Illuminate\Support\Manager;

/**
 * Resolves the configured trace exporter driver. Braintrust ships by default;
 * add another by registering a `create{Name}Driver()` method here, or from the
 * host app at runtime via `TraceExporterManager::extend()`.
 */
class TraceExporterManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('ai-companion.tracing.exporter', 'braintrust');
    }

    public function createBraintrustDriver(): TraceExporter
    {
        return $this->container->make(BraintrustExporter::class);
    }
}

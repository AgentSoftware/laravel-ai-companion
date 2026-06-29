<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Exporters;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use Illuminate\Support\Manager;

/**
 * Resolves the configured experiment exporter driver. Braintrust ships by
 * default; add another by registering a `create{Name}Driver()` method here, or
 * from the host app at runtime via `ExperimentExporterManager::extend()`.
 */
class ExperimentExporterManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('ai-companion.eval.exporter', 'braintrust');
    }

    public function createBraintrustDriver(): ExperimentExporter
    {
        return $this->container->make(BraintrustExperimentExporter::class);
    }
}

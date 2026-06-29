<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\Exporters\BraintrustExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\Exporters\ExperimentExporterManager;
use AgentSoftware\LaravelAiCompanion\Eval\RepoInfo;

it('resolves the braintrust driver by default', function (): void {
    expect(app(ExperimentExporter::class))->toBeInstanceOf(BraintrustExperimentExporter::class);
});

it('throws for an unknown driver', function (): void {
    config()->set('ai-companion.eval.exporter', 'nope');

    app(ExperimentExporterManager::class)->driver();
})->throws(InvalidArgumentException::class);

it('lets the host app register a custom driver', function (): void {
    $custom = new class implements ExperimentExporter
    {
        public function enabled(): bool
        {
            return true;
        }

        public function export(string $experiment, array $events, array $metadata = [], ?RepoInfo $repoInfo = null): string
        {
            return 'custom';
        }
    };

    app(ExperimentExporterManager::class)->extend('custom', fn (): ExperimentExporter => $custom);
    config()->set('ai-companion.eval.exporter', 'custom');

    expect(app(ExperimentExporterManager::class)->driver())->toBe($custom);
});

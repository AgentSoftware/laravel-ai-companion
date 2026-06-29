<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Exporters\BraintrustExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Exporters\TraceExporterManager;

it('resolves the braintrust driver by default', function (): void {
    expect(app(TraceExporter::class))->toBeInstanceOf(BraintrustExporter::class);
});

it('throws for an unknown driver', function (): void {
    config()->set('ai-companion.tracing.exporter', 'nope');

    app(TraceExporterManager::class)->driver();
})->throws(InvalidArgumentException::class);

it('lets the host app register a custom driver', function (): void {
    $custom = new class implements TraceExporter
    {
        public function enabled(): bool
        {
            return true;
        }

        public function ship(array $spans): void {}
    };

    app(TraceExporterManager::class)->extend('custom', fn (): TraceExporter => $custom);
    config()->set('ai-companion.tracing.exporter', 'custom');

    expect(app(TraceExporterManager::class)->driver())->toBe($custom);
});

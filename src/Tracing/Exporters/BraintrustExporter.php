<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Exporters;

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BraintrustExporter implements TraceExporter
{
    public function enabled(): bool
    {
        return (bool) config('ai-companion.braintrust.enabled')
            && filled(config('ai-companion.braintrust.api_key'));
    }

    public function ship(array $spans): void
    {
        $this->client()
            ->post("/v1/project_logs/{$this->projectId()}/insert", [
                'events' => array_map($this->toBraintrustEvent(...), $spans),
            ])
            ->throw();
    }

    /**
     * Map a neutral span to a Braintrust insert event.
     *
     * @param  array<string, mixed>  $span
     * @return array<string, mixed>
     */
    private function toBraintrustEvent(array $span): array
    {
        return array_filter([
            'id' => $span['id'],
            'span_id' => $span['id'],
            'root_span_id' => $span['trace_id'],
            'span_parents' => filled($span['parent_id']) ? [$span['parent_id']] : null,
            'span_attributes' => [
                'name' => $span['name'],
                'type' => $span['type'],
            ],
            'input' => $span['input'],
            'output' => $span['output'],
            'error' => $span['error'],
            'metadata' => $span['metadata'],
            'metrics' => array_filter($span['metrics'], fn (mixed $value): bool => $value !== null),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Resolve the Braintrust project id for the configured project name.
     *
     * Braintrust's create endpoint returns the existing project unmodified
     * when one with the same name already exists, so this is a find-or-create.
     */
    private function projectId(): string
    {
        $project = config('ai-companion.braintrust.project') ?? config('app.name');

        return Cache::rememberForever(
            "ai-companion:braintrust:project-id:{$project}",
            fn (): string => (string) $this->client()
                ->post('/v1/project', ['name' => $project])
                ->throw()
                ->json('id'),
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('ai-companion.braintrust.api_url'))
            ->withToken(config('ai-companion.braintrust.api_key'));
    }
}

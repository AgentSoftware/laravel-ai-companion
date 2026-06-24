<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Exporters;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BraintrustExperimentExporter implements ExperimentExporter
{
    public function enabled(): bool
    {
        return filled(config('ai-companion.braintrust.api_key'));
    }

    public function export(string $experiment, array $events, array $metadata = [], array $repoInfo = []): string
    {
        $experimentId = (string) $this->client()
            ->post('/v1/experiment', array_filter([
                'project_id' => $this->projectId(),
                'name' => $experiment,
                // A fresh experiment per run so each local re-run is its own
                // comparable record rather than appending to the last one.
                'ensure_new' => true,
                'metadata' => $metadata !== [] ? $metadata : null,
                'repo_info' => $repoInfo !== [] ? $repoInfo : null,
            ], fn (mixed $value): bool => $value !== null))
            ->throw()
            ->json('id');

        $this->client()
            ->post("/v1/experiment/{$experimentId}/insert", [
                'events' => array_map($this->toExperimentEvent(...), $events),
            ])
            ->throw();

        return $experimentId;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function toExperimentEvent(array $event): array
    {
        // Braintrust requires metadata and metrics to be json objects, but an
        // empty PHP array encodes to a json array — omit them when empty.
        $metrics = array_filter($event['metrics'] ?? [], fn (mixed $value): bool => $value !== null);

        return array_filter([
            'input' => $event['input'] ?? null,
            'output' => $event['output'] ?? null,
            'scores' => $event['scores'] ?? null,
            'expected' => $event['expected'] ?? null,
            'metadata' => ($event['metadata'] ?? []) !== [] ? $event['metadata'] : null,
            'metrics' => $metrics !== [] ? $metrics : null,
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

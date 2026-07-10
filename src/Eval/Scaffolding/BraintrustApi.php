<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use AgentSoftware\LaravelAiCompanion\Braintrust\InteractsWithBraintrustApi;
use Illuminate\Http\Client\Response;

/**
 * The single Braintrust-aware client for the eval tooling: scaffolding reads
 * (datasets, dataset events, recent project-log events) and publish writes
 * (scorer functions, invocations, online scoring rules). Everything else
 * speaks neutral shapes — swap operators by replacing this class.
 */
class BraintrustApi
{
    use InteractsWithBraintrustApi;

    /** @return array<int, array{id: string, name: string}> */
    public function datasets(): array
    {
        $objects = (array) $this->request(fn (): Response => $this->client()
            ->get('/v1/dataset', ['project_id' => $this->projectId(), 'limit' => 100]))
            ->json('objects', []);

        return collect($objects)
            ->map(fn (array $dataset): array => [
                'id' => (string) $dataset['id'],
                'name' => (string) $dataset['name'],
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function datasetEvents(string $datasetId, int $limit): array
    {
        return (array) $this->request(fn (): Response => $this->client()
            ->post("/v1/dataset/{$datasetId}/fetch", ['limit' => $limit]))
            ->json('events', []);
    }

    /**
     * Recent LLM spans from the project logs, newest first, optionally filtered
     * to one agent. Filtering happens server-side via BTQL — the plain fetch
     * endpoint has no filter, and a busy project's most recent events are
     * mostly tool spans, so fetch-then-filter finds nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function logEvents(int $limit, ?string $agentName = null): array
    {
        $filter = "span_attributes.type = 'llm'";

        if ($agentName !== null) {
            $escaped = str_replace("'", "''", $agentName);
            $filter .= " and span_attributes.name ILIKE '%{$escaped}%'";
        }

        $query = "select: * from: project_logs('{$this->projectId()}') filter: {$filter} sort: created desc limit: {$limit}";

        return (array) $this->request(fn (): Response => $this->client()
            ->post('/btql', ['query' => $query, 'fmt' => 'json']))
            ->json('data', []);
    }

    /**
     * Normalize a Braintrust event (dataset or log) into a dataset row.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function toRow(array $event, bool $includeExpected, bool $includeMetadata): array
    {
        $input = data_get($event, 'input', '');
        // Our agent spans ship input as {prompt, instructions} (see SpanBuilder);
        // instructions are omitted — evals rebuild the real agent, whose code
        // supplies them. Other shapes: {input: ...} wrappers or raw values.
        $prompt = is_array($input)
            ? data_get($input, 'prompt') ?? data_get($input, 'input') ?? json_encode($input)
            : $input;

        $row = ['prompt' => (string) $prompt];

        $expected = data_get($event, 'expected') ?? data_get($event, 'output');

        if ($includeExpected && $expected !== null) {
            $row['expected'] = $expected;
        }

        $metadata = data_get($event, 'metadata');

        if ($includeMetadata && is_array($metadata)) {
            $row += collect($metadata)->filter(fn (mixed $value): bool => is_scalar($value))->all();
        }

        return $row;
    }

    /**
     * Create-or-update a node code scorer function by slug; returns the id.
     * Skips the write entirely when the stored code already matches.
     */
    public function upsertFunction(string $slug, string $name, string $code): string
    {
        $existing = collect((array) $this->request(fn (): Response => $this->client()
            ->get('/v1/function', ['project_id' => $this->projectId(), 'slug' => $slug, 'limit' => 1]))
            ->json('objects', []))->first();

        $functionData = [
            'type' => 'code',
            'data' => [
                'type' => 'inline',
                'runtime_context' => ['runtime' => 'node', 'version' => '20'],
                'code' => $code,
            ],
        ];

        if ($existing === null) {
            return (string) $this->request(fn (): Response => $this->client()->post('/v1/function', [
                'project_id' => $this->projectId(),
                'name' => $name,
                'slug' => $slug,
                'function_type' => 'scorer',
                'function_data' => $functionData,
            ]))->json('id');
        }

        if (data_get($existing, 'function_data.data.code') !== $code) {
            $this->request(fn (): Response => $this->client()
                ->patch("/v1/function/{$existing['id']}", ['function_data' => $functionData]));
        }

        return (string) $existing['id'];
    }

    /**
     * Run a function server-side (the publish smoke test runs the scorer in the
     * REAL sandbox — local Node can diverge from it). Returns the handler's raw
     * decoded return value: callers must distinguish a bare number (loggable as
     * a score) from a {score: ...} object (loggable only with a name).
     *
     * @param  array<string, mixed>  $input
     */
    public function invokeFunction(string $id, array $input): mixed
    {
        return $this->request(fn (): Response => $this->client()
            ->post("/v1/function/{$id}/invoke", ['input' => $input]))
            ->json();
    }

    /**
     * Create-or-update the project's online scoring rule by name. Re-publishing
     * reconciles: the rule's scorer list becomes exactly $scorerIds.
     *
     * @param  array<int, string>  $scorerIds
     * @param  array<int, string>  $spanNames
     */
    public function upsertOnlineRule(string $name, array $scorerIds, array $spanNames, float $samplingRate, string $description = ''): void
    {
        $existing = collect((array) $this->request(fn (): Response => $this->client()
            ->get('/v1/project_score', [
                'project_id' => $this->projectId(),
                'project_score_name' => $name,
                'score_type' => 'online',
                'limit' => 1,
            ]))
            ->json('objects', []))->first();

        $config = ['online' => [
            'sampling_rate' => $samplingRate,
            'scorers' => collect($scorerIds)->map(fn (string $id): array => ['type' => 'function', 'id' => $id])->values()->all(),
            'apply_to_root_span' => false,
            'apply_to_span_names' => $spanNames,
        ]];

        if ($existing === null) {
            $this->request(fn (): Response => $this->client()->post('/v1/project_score', [
                'project_id' => $this->projectId(),
                'name' => $name,
                'description' => $description,
                'score_type' => 'online',
                'config' => $config,
            ]));

            return;
        }

        $this->request(fn (): Response => $this->client()
            ->patch("/v1/project_score/{$existing['id']}", ['description' => $description, 'config' => $config]));
    }
}

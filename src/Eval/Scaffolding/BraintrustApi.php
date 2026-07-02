<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-side Braintrust client for scaffolding: list datasets, fetch dataset
 * events, fetch recent project-log events. The ONLY Braintrust-aware class in
 * Eval/Scaffolding — everything else speaks DatasetSource rows.
 */
class BraintrustApi
{
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

    /** @param  callable(): Response  $send */
    private function request(callable $send): Response
    {
        try {
            return $send()->throw();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 421) {
                throw new RuntimeException(
                    'Braintrust returned 421 DataPlaneRedirectError — your org is pinned to another data plane. '
                    .'Set BRAINTRUST_API_URL=https://api-eu.braintrust.dev and retry.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    private function projectId(): string
    {
        $project = config('ai-companion.braintrust.project') ?? config('app.name');

        return Cache::rememberForever(
            "ai-companion:braintrust:project-id:{$project}",
            fn (): string => (string) $this->request(fn (): Response => $this->client()
                ->post('/v1/project', ['name' => $project]))
                ->json('id'),
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('ai-companion.braintrust.api_url'))
            ->withToken((string) config('ai-companion.braintrust.api_key'))
            ->connectTimeout(5)
            ->timeout(30);
    }
}

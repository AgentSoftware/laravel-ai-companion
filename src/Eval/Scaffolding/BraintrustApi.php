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

        return array_values(array_map(fn (array $dataset): array => [
            'id' => (string) $dataset['id'],
            'name' => (string) $dataset['name'],
        ], $objects));
    }

    /** @return array<int, array<string, mixed>> */
    public function datasetEvents(string $datasetId, int $limit): array
    {
        return (array) $this->request(fn (): Response => $this->client()
            ->post("/v1/dataset/{$datasetId}/fetch", ['limit' => $limit]))
            ->json('events', []);
    }

    /** @return array<int, array<string, mixed>> */
    public function logEvents(int $limit): array
    {
        return (array) $this->request(fn (): Response => $this->client()
            ->post("/v1/project_logs/{$this->projectId()}/fetch", ['limit' => $limit]))
            ->json('events', []);
    }

    /**
     * Normalize a Braintrust event (dataset or log) into a dataset row.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function toRow(array $event, bool $includeExpected, bool $includeMetadata): array
    {
        $input = $event['input'] ?? '';
        // Log spans wrap the prompt as {"input": "..."}; datasets may hold it raw.
        $prompt = is_array($input) ? ($input['input'] ?? json_encode($input)) : $input;

        $row = ['prompt' => (string) $prompt];

        $expected = $event['expected'] ?? $event['output'] ?? null;

        if ($includeExpected && $expected !== null) {
            $row['expected'] = $expected;
        }

        if ($includeMetadata && is_array($event['metadata'] ?? null)) {
            $row += array_filter($event['metadata'], fn (mixed $value): bool => is_scalar($value));
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
            ->withToken((string) config('ai-companion.braintrust.api_key'));
    }
}

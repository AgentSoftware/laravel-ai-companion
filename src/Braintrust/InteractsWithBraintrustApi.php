<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Braintrust;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared HTTP client, project-id resolution, and error handling for classes
 * that talk to the Braintrust REST API directly (not through the neutral
 * TraceExporter pipeline) — currently BraintrustApi (eval scaffolding) and
 * BraintrustFeedbackClient (user feedback).
 */
trait InteractsWithBraintrustApi
{
    /** @param  callable(): Response  $send */
    protected function request(callable $send): Response
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

    protected function projectId(): string
    {
        $project = config('ai-companion.braintrust.project') ?? config('app.name');

        return Cache::rememberForever(
            "ai-companion:braintrust:project-id:{$project}",
            fn (): string => (string) $this->request(fn (): Response => $this->client()
                ->post('/v1/project', ['name' => $project]))
                ->json('id'),
        );
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('ai-companion.braintrust.api_url'))
            ->withToken((string) config('ai-companion.braintrust.api_key'))
            ->connectTimeout(5)
            ->timeout(30);
    }
}

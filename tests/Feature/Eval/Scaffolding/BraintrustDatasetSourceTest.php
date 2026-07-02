<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustDatasetSource;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');
});

it('maps braintrust dataset events to rows', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::response(['events' => [
            [
                'input' => 'Plan pages for acme.com',
                'expected' => ['text' => 'the plan'],
                'metadata' => ['company_brand_tone' => 'friendly', 'nested' => ['drop' => 'me']],
            ],
            ['input' => ['input' => 'wrapped prompt']],
        ]]),
    ]);

    $rows = new BraintrustDatasetSource(new BraintrustApi, 'ds-1')
        ->fetch(limit: 25, includeExpected: true, includeMetadata: true);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['prompt' => 'Plan pages for acme.com', 'expected' => ['text' => 'the plan'], 'company_brand_tone' => 'friendly'])
        ->and($rows[1])->toBe(['prompt' => 'wrapped prompt']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/dataset/ds-1/fetch')
        && $request['limit'] === 25);
});

it('lists datasets for the configured project', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [
            ['id' => 'ds-1', 'name' => 'page-planner', 'ignored' => true],
        ]]),
    ]);

    expect(new BraintrustApi()->datasets())->toBe([['id' => 'ds-1', 'name' => 'page-planner']]);
});

it('turns a 421 into an actionable EU data-plane error', function (): void {
    Http::fake(['api.braintrust.dev/*' => Http::response(['error' => 'DataPlaneRedirectError'], 421)]);

    expect(fn () => new BraintrustApi()->datasets())
        ->toThrow(RuntimeException::class, 'BRAINTRUST_API_URL=https://api-eu.braintrust.dev');
});

it('rethrows non-421 request exceptions unchanged', function (): void {
    Http::fake(['api.braintrust.dev/*' => Http::response(['error' => 'Internal Server Error'], 500)]);

    expect(fn () => new BraintrustApi()->datasets())
        ->toThrow(RequestException::class);
});

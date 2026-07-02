<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustLogsSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['data' => [
            [
                'input' => ['input' => 'Plan pages for acme.com'],
                'output' => ['text' => 'the plan'],
                'span_attributes' => ['name' => 'PagePlannerAgent'],
                'metadata' => ['model' => 'claude-sonnet-5'],
            ],
        ]]),
    ]);
});

it('queries btql with a server-side agent filter and maps events to rows', function (): void {
    $rows = new BraintrustLogsSource(new BraintrustApi, 'PagePlannerAgent')
        ->fetch(limit: 10, includeExpected: true, includeMetadata: true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['prompt'])->toBe('Plan pages for acme.com')
        ->and($rows[0]['expected'])->toBe(['text' => 'the plan'])
        ->and($rows[0]['model'])->toBe('claude-sonnet-5');

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/btql')) {
            return false;
        }

        $query = (string) $request['query'];

        return str_contains($query, "project_logs('proj-1')")
            && str_contains($query, "span_attributes.type = 'llm'")
            && str_contains($query, "span_attributes.name ILIKE '%PagePlannerAgent%'")
            && str_contains($query, 'limit: 10');
    });
});

it('omits the name filter but still selects llm spans when no agent is given', function (): void {
    new BraintrustLogsSource(new BraintrustApi)
        ->fetch(limit: 25, includeExpected: false, includeMetadata: false);

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/btql')) {
            return false;
        }

        $query = (string) $request['query'];

        return str_contains($query, "span_attributes.type = 'llm'")
            && ! str_contains($query, 'ILIKE')
            && str_contains($query, 'limit: 25');
    });
});

it('escapes single quotes in the agent filter', function (): void {
    new BraintrustLogsSource(new BraintrustApi, "O'Brien")
        ->fetch(limit: 5, includeExpected: false, includeMetadata: false);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/btql')
        && str_contains((string) $request['query'], "ILIKE '%O''Brien%'"));
});

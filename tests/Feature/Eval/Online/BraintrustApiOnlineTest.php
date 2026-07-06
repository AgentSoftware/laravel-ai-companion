<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['data' => [
            ['id' => 'span-1', 'input' => ['prompt' => 'p'], 'output' => 'answer'],
        ]]),
        'api.braintrust.dev/v1/project_logs/proj-1/insert' => Http::response(['row_ids' => ['span-1']]),
    ]);
});

it('queries unscored llm spans for an agent within the lookback window', function (): void {
    $spans = new BraintrustApi()->unscoredSpans('PagePlannerAgent', 'quality', lookbackMinutes: 60, limit: 200);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['id'])->toBe('span-1');

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/btql')) {
            return false;
        }

        $query = (string) $request['query'];

        return str_contains($query, "span_attributes.type = 'llm'")
            && str_contains($query, "span_attributes.name ILIKE '%PagePlannerAgent%'")
            && str_contains($query, 'scores.quality IS NULL')
            && str_contains($query, 'created > now() - INTERVAL 60 MINUTE')
            && str_contains($query, 'limit: 200');
    });
});

it('merges scores onto existing spans', function (): void {
    new BraintrustApi()->mergeScores([
        ['id' => 'span-1', '_is_merge' => true, 'scores' => ['quality' => 0.9]],
    ]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/project_logs/proj-1/insert')
        && $request['events'][0]['id'] === 'span-1'
        && $request['events'][0]['_is_merge'] === true
        && $request['events'][0]['scores'] === ['quality' => 0.9]);
});

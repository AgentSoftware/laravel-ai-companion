<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustLogsSource;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/project_logs/proj-1/fetch' => Http::response(['events' => [
            [
                'input' => ['input' => 'Plan pages for acme.com'],
                'output' => ['text' => 'the plan'],
                'span_attributes' => ['name' => 'PagePlannerAgent'],
                'metadata' => ['model' => 'claude-sonnet-5'],
            ],
            [
                'input' => 'other prompt',
                'output' => ['text' => 'other'],
                'span_attributes' => ['name' => 'OtherAgent'],
            ],
        ]]),
    ]);
});

it('maps log events to rows and filters by agent name', function (): void {
    $rows = new BraintrustLogsSource(new BraintrustApi, 'PagePlannerAgent')
        ->fetch(limit: 50, includeExpected: true, includeMetadata: true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['prompt'])->toBe('Plan pages for acme.com')
        ->and($rows[0]['expected'])->toBe(['text' => 'the plan'])
        ->and($rows[0]['model'])->toBe('claude-sonnet-5');
});

it('returns all events when no agent filter is given', function (): void {
    $rows = new BraintrustLogsSource(new BraintrustApi)
        ->fetch(limit: 50, includeExpected: false, includeMetadata: false);

    expect($rows)->toHaveCount(2)
        ->and($rows[1])->toBe(['prompt' => 'other prompt']);
});

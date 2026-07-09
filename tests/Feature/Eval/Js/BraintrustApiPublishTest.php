<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::fake(['api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1'])]);
});

it('creates the function when the slug does not exist', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/function?*' => Http::response(['objects' => []]),
        'api.braintrust.dev/v1/function' => Http::response(['id' => 'fn-new']),
    ]);

    $id = new BraintrustApi()->upsertFunction('my-check', 'My Check', 'function handler() {}');

    expect($id)->toBe('fn-new');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/function')
        && $request['slug'] === 'my-check'
        && $request['function_type'] === 'scorer'
        && $request['function_data']['data']['runtime_context'] === ['runtime' => 'node', 'version' => '20']
        && $request['function_data']['data']['code'] === 'function handler() {}');
});

it('patches the function when the code changed', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/function?*' => Http::response(['objects' => [[
            'id' => 'fn-1',
            'function_data' => ['type' => 'code', 'data' => ['type' => 'inline', 'code' => 'old code']],
        ]]]),
        'api.braintrust.dev/v1/function/fn-1' => Http::response(['id' => 'fn-1']),
    ]);

    $id = new BraintrustApi()->upsertFunction('my-check', 'My Check', 'new code');

    expect($id)->toBe('fn-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/v1/function/fn-1')
        && $request['function_data']['data']['code'] === 'new code');
});

it('skips the write when the code is unchanged', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/function?*' => Http::response(['objects' => [[
            'id' => 'fn-1',
            'function_data' => ['type' => 'code', 'data' => ['type' => 'inline', 'code' => 'same']],
        ]]]),
    ]);

    expect(new BraintrustApi()->upsertFunction('my-check', 'My Check', 'same'))->toBe('fn-1');

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PATCH'], true)
        && str_contains($request->url(), '/v1/function')
        && ! str_contains($request->url(), '?'));
});

it('invokes a function and returns the decoded result', function (): void {
    Http::fake(['api.braintrust.dev/v1/function/fn-1/invoke' => Http::response(['score' => 1, 'metadata' => ['ok' => true]])]);

    $result = new BraintrustApi()->invokeFunction('fn-1', ['output' => 'smoke test', 'input' => []]);

    expect($result['score'])->toBe(1);
});

it('returns a scalar invoke response as-is', function (): void {
    // A bare number is a valid scorer return — the caller must be able to tell
    // it apart from a {score: ...} object, which needs a name to be loggable.
    Http::fake(['api.braintrust.dev/v1/function/fn-1/invoke' => Http::response('0.5', 200, ['Content-Type' => 'application/json'])]);

    expect(new BraintrustApi()->invokeFunction('fn-1', []))->toBe(0.5);
});

it('creates the online rule when none exists by name', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project_score?*' => Http::response(['objects' => []]),
        'api.braintrust.dev/v1/project_score' => Http::response(['id' => 'rule-1']),
    ]);

    new BraintrustApi()->upsertOnlineRule('page-planner (online)', ['fn-1'], ['PagePlannerAgent'], 0.25, 'Scores live spans.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/project_score')
        && $request['score_type'] === 'online'
        && $request['description'] === 'Scores live spans.'
        && $request['config']['online']['sampling_rate'] === 0.25
        && $request['config']['online']['scorers'] === [['type' => 'function', 'id' => 'fn-1']]
        && $request['config']['online']['apply_to_span_names'] === ['PagePlannerAgent']
        && $request['config']['online']['apply_to_root_span'] === false);
});

it('patches the online rule when one exists by name', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project_score?*' => Http::response(['objects' => [['id' => 'rule-1']]]),
        'api.braintrust.dev/v1/project_score/rule-1' => Http::response(['id' => 'rule-1']),
    ]);

    new BraintrustApi()->upsertOnlineRule('page-planner (online)', ['fn-2'], ['PagePlannerAgent'], 1.0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/v1/project_score/rule-1')
        && $request['config']['online']['scorers'] === [['type' => 'function', 'id' => 'fn-2']]);
});

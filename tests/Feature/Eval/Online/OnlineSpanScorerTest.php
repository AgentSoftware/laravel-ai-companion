<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\RequiresExpected;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Online\OnlineSpanScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Online\SpanSampler;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Online\OnlineStubTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function onlinePassingScorer(): Scorer
{
    return new class implements Scorer
    {
        public function score(EvalSubject $subject): Score
        {
            return new Score('always_one', 1.0);
        }
    };
}

function onlineThrowingScorer(): Scorer
{
    return new class implements Scorer
    {
        public function score(EvalSubject $subject): Score
        {
            throw new RuntimeException('boom');
        }
    };
}

function onlineExpectedScorer(): Scorer
{
    return new class implements RequiresExpected, Scorer
    {
        public function score(EvalSubject $subject): Score
        {
            return new Score('needs_expected', 1.0);
        }
    };
}

/**
 * @param  array<int, EvalSubject>  &$captured
 */
function onlineRecordingScorer(array &$captured): Scorer
{
    return new class($captured) implements Scorer
    {
        public function __construct(private array &$captured) {}

        public function score(EvalSubject $subject): Score
        {
            $this->captured[] = $subject;

            return new Score('recorded', 1.0);
        }
    };
}

/**
 * @param  array<int, array<string, mixed>>  $spans
 */
function fakeOnlineBraintrust(array $spans): void
{
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['data' => $spans]),
        'api.braintrust.dev/v1/project_logs/proj-1/insert' => Http::response(['row_ids' => []]),
    ]);
}

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');
});

it('scores unscored spans and merges scores plus the sentinel back', function (): void {
    fakeOnlineBraintrust([
        ['id' => 'span-1', 'output' => 'answer one', 'input' => ['prompt' => 'brief one']],
        ['id' => 'span-2', 'output' => ['text' => 'answer two'], 'input' => ['prompt' => 'brief two']],
    ]);

    $scored = new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlinePassingScorer()]), sampleRate: 1.0, lookbackMinutes: 60);

    expect($scored)->toBe(2);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/insert')) {
            return false;
        }

        $event = $request['events'][0];

        return $event['id'] === 'span-1'
            && $event['_is_merge'] === true
            && $event['scores']['always_one'] === 1.0
            && $event['scores']['online_stub_online'] === 1.0;
    });
});

it('passes the span prompt to scorers under both prompt and brief input keys', function (): void {
    fakeOnlineBraintrust([
        ['id' => 'span-1', 'output' => 'answer one', 'input' => ['prompt' => 'brief one']],
        ['id' => 'span-2', 'output' => ['text' => 'answer two'], 'input' => ['prompt' => 'brief two']],
    ]);

    $captured = [];

    new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlineRecordingScorer($captured)]), sampleRate: 1.0, lookbackMinutes: 60);

    expect($captured)->toHaveCount(2);

    expect($captured[0]->input['prompt'])->toBe('brief one');
    expect($captured[0]->input['brief'])->toBe('brief one');
    expect($captured[0]->input['text'])->toBe('answer one');
    expect($captured[0]->output)->toBe(['text' => 'answer one']);

    expect($captured[1]->input['prompt'])->toBe('brief two');
    expect($captured[1]->input['brief'])->toBe('brief two');
    expect($captured[1]->input['text'])->toBe('answer two');
    expect($captured[1]->output)->toBe(['text' => 'answer two']);
});

it('falls back to casting the raw input when the span input is not array-shaped', function (): void {
    fakeOnlineBraintrust([
        ['id' => 'span-1', 'output' => 'answer one', 'input' => 'not-an-array'],
    ]);

    $captured = [];

    new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlineRecordingScorer($captured)]), sampleRate: 1.0, lookbackMinutes: 60);

    expect($captured)->toHaveCount(1);
    expect($captured[0]->input['prompt'])->toBe('not-an-array');
    expect($captured[0]->input['brief'])->toBe('not-an-array');
});

it('falls back to the input.input key when input.prompt is absent', function (): void {
    fakeOnlineBraintrust([
        ['id' => 'span-1', 'output' => 'answer one', 'input' => ['input' => 'brief via input key']],
    ]);

    $captured = [];

    new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlineRecordingScorer($captured)]), sampleRate: 1.0, lookbackMinutes: 60);

    expect($captured)->toHaveCount(1);
    expect($captured[0]->input['prompt'])->toBe('brief via input key');
    expect($captured[0]->input['brief'])->toBe('brief via input key');
});

it('skips RequiresExpected scorers with a warning and still runs the rest', function (): void {
    fakeOnlineBraintrust([
        ['id' => 'span-1', 'output' => 'answer one', 'input' => ['prompt' => 'brief one']],
        ['id' => 'span-2', 'output' => ['text' => 'answer two'], 'input' => ['prompt' => 'brief two']],
    ]);

    Log::spy();

    $scored = new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlineExpectedScorer(), onlinePassingScorer()]), sampleRate: 1.0, lookbackMinutes: 60);

    expect($scored)->toBe(2);
    Log::shouldHaveReceived('warning')->once();
});

it('does not fetch at all when every scorer requires expected context', function (): void {
    $scored = new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlineExpectedScorer()]), sampleRate: 1.0, lookbackMinutes: 60);

    expect($scored)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/btql'));
});

it('skips spans whose scorers throw so they retry next run', function (): void {
    fakeOnlineBraintrust([
        ['id' => 'span-1', 'output' => 'answer one', 'input' => ['prompt' => 'brief one']],
        ['id' => 'span-2', 'output' => ['text' => 'answer two'], 'input' => ['prompt' => 'brief two']],
    ]);

    $scored = new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlineThrowingScorer()]), sampleRate: 1.0, lookbackMinutes: 60);

    expect($scored)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/insert'));
});

it('skips spans that have no string id', function (): void {
    fakeOnlineBraintrust([
        ['id' => 123, 'output' => 'answer one', 'input' => ['prompt' => 'brief one']],
        ['output' => 'answer two', 'input' => ['prompt' => 'brief two']],
    ]);

    $scored = new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlinePassingScorer()]), sampleRate: 1.0, lookbackMinutes: 60);

    expect($scored)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/insert'));
});

it('applies the sample rate', function (): void {
    fakeOnlineBraintrust([
        ['id' => 'span-1', 'output' => 'answer one', 'input' => ['prompt' => 'brief one']],
        ['id' => 'span-2', 'output' => ['text' => 'answer two'], 'input' => ['prompt' => 'brief two']],
    ]);

    $scored = new OnlineSpanScorer(new BraintrustApi, new SpanSampler)
        ->score(new OnlineStubTarget([onlinePassingScorer()]), sampleRate: 0.0, lookbackMinutes: 60);

    expect($scored)->toBe(0);
});

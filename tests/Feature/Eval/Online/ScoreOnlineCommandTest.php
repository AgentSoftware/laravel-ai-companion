<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Online\OnlineStubTarget;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function commandStubScorer(): Scorer
{
    return new class implements Scorer
    {
        public function score(EvalSubject $subject): Score
        {
            return new Score('always_one', 1.0);
        }
    };
}

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    // OnlineStubTarget requires constructor scorers — bind so the container can resolve it.
    app()->bind(OnlineStubTarget::class, fn (): OnlineStubTarget => new OnlineStubTarget([commandStubScorer()]));
});

it('scores every registered target', function (): void {
    config()->set('ai-companion.eval.online.targets', [OnlineStubTarget::class => 1.0]);

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['data' => [
            ['id' => 'span-1', 'output' => 'answer'],
        ]]),
        'api.braintrust.dev/v1/project_logs/proj-1/insert' => Http::response(['row_ids' => []]),
    ]);

    $this->artisan('ai:score-online')
        ->expectsOutputToContain('online-stub: scored 1 span(s)')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/insert'));
});

it('warns and exits cleanly when no targets are registered', function (): void {
    config()->set('ai-companion.eval.online.targets', []);

    Http::fake();

    $this->artisan('ai:score-online')->assertSuccessful();

    Http::assertNothingSent();
});

it('filters to one target and honours the lookback override', function (): void {
    config()->set('ai-companion.eval.online.targets', [OnlineStubTarget::class => 1.0]);

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['data' => [
            ['id' => 'span-1', 'output' => 'answer'],
        ]]),
        'api.braintrust.dev/v1/project_logs/proj-1/insert' => Http::response(['row_ids' => []]),
    ]);

    $this->artisan('ai:score-online', ['--target' => 'online-stub', '--lookback' => 120])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/btql')
        && str_contains((string) $request['query'], 'INTERVAL 120 MINUTE'));
});

it('reports an unknown --target and fails', function (): void {
    config()->set('ai-companion.eval.online.targets', [OnlineStubTarget::class => 1.0]);

    Http::fake();

    $this->artisan('ai:score-online', ['--target' => 'nope'])->assertFailed();
});

it('continues past a failing target', function (): void {
    config()->set('ai-companion.eval.online.targets', [
        'Not\\A\\Real\\Class' => 1.0,
        OnlineStubTarget::class => 1.0,
    ]);

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['data' => [
            ['id' => 'span-1', 'output' => 'answer'],
        ]]),
        'api.braintrust.dev/v1/project_logs/proj-1/insert' => Http::response(['row_ids' => []]),
    ]);

    $this->artisan('ai:score-online')
        ->expectsOutputToContain('online-stub: scored 1 span(s)')
        ->assertSuccessful();
});

it('warns and continues when a target throws while scoring', function (): void {
    config()->set('ai-companion.eval.online.targets', [OnlineStubTarget::class => 1.0]);

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['message' => 'boom'], 500),
    ]);

    $this->artisan('ai:score-online')
        ->expectsOutputToContain('online-stub: failed')
        ->assertSuccessful();
});

it('does not register the schedule when disabled', function (): void {
    config()->set('ai-companion.eval.online.enabled', false);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'ai:score-online'));

    expect($events)->toHaveCount(0);
});

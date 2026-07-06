<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Js\JsScorer;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Js\JsStubTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    // A per-process temp dir, NOT base_path('resources/ai/scorers'): the
    // scaffold command tests delete that directory in their beforeEach, and
    // under --parallel the workers share the filesystem — a real CI flake.
    $dir = sys_get_temp_dir().'/publish-eval-scorers-'.getmypid();
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/my-check.js", 'async function handler({ output }) { return { score: 1 }; }');

    app()->bind(JsStubTarget::class, fn (): JsStubTarget => new JsStubTarget([
        new JsScorer("{$dir}/my-check.js"),
    ]));

    config()->set('ai-companion.eval.targets', [JsStubTarget::class]);
});

function fakePublishBraintrust(int $invokeStatus = 200): void
{
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/function?*' => Http::response(['objects' => []]),
        'api.braintrust.dev/v1/function/fn-1/invoke' => Http::response(
            $invokeStatus === 200 ? ['score' => 1] : ['error' => 'sandbox boom'],
            $invokeStatus,
        ),
        'api.braintrust.dev/v1/function' => Http::response(['id' => 'fn-1']),
        'api.braintrust.dev/v1/project_score?*' => Http::response(['objects' => []]),
        'api.braintrust.dev/v1/project_score' => Http::response(['id' => 'rule-1']),
    ]);
}

it('publishes selected js scorers and creates the online rule', function (): void {
    fakePublishBraintrust();

    $this->artisan('ai:publish-eval', ['--target' => 'js-stub', '--scorers' => 'my_check', '--sample' => '0.5'])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/project_score')
        && $request['name'] === 'js-stub (online)'
        && $request['config']['online']['sampling_rate'] === 0.5
        && str_contains((string) $request['description'], 'my_check')
        && $request['config']['online']['apply_to_span_names'] === ['JsStub', 'JsStubAgent']);
});

it('runs fully interactively', function (): void {
    fakePublishBraintrust();

    $this->artisan('ai:publish-eval')
        ->expectsQuestion('Which eval do you want to publish for online scoring?', JsStubTarget::class)
        ->expectsQuestion('Which scorers should run against live traffic?', ['my_check'])
        ->expectsQuestion('What fraction of live traffic should be scored? (0.0–1.0)', '1.0')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/project_score'));
});

it('aborts before the rule when the sandbox smoke test fails', function (): void {
    fakePublishBraintrust(invokeStatus: 500);

    $this->artisan('ai:publish-eval', ['--target' => 'js-stub', '--scorers' => 'my_check', '--sample' => '1'])
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/project_score')
        && $request->method() === 'POST');
});

it('errors when the target has no js scorers', function (): void {
    app()->bind(JsStubTarget::class, fn (): JsStubTarget => new JsStubTarget([]));

    $this->artisan('ai:publish-eval', ['--target' => 'js-stub'])->assertFailed();

    Http::assertNothingSent();
});

it('errors on an unknown target or scorer slug', function (): void {
    $this->artisan('ai:publish-eval', ['--target' => 'nope'])->assertFailed();
    $this->artisan('ai:publish-eval', ['--target' => 'js-stub', '--scorers' => 'nope', '--sample' => '1'])->assertFailed();
});

it('warns and succeeds when nothing is selected interactively', function (): void {
    fakePublishBraintrust();

    $this->artisan('ai:publish-eval', ['--sample' => '1'])
        ->expectsQuestion('Which eval do you want to publish for online scoring?', JsStubTarget::class)
        ->expectsQuestion('Which scorers should run against live traffic?', [])
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v1/function'));
});

it('errors when no eval targets are configured', function (): void {
    config()->set('ai-companion.eval.targets', []);

    $this->artisan('ai:publish-eval')->assertFailed();
});

it('drops unresolvable target classes and continues', function (): void {
    fakePublishBraintrust();

    config()->set('ai-companion.eval.targets', [
        'Not\\A\\Real\\Class',
        JsStubTarget::class,
    ]);

    $this->artisan('ai:publish-eval', ['--target' => 'js-stub', '--scorers' => 'my_check', '--sample' => '1'])
        ->assertSuccessful();
});

it('rejects an invalid sample rate before publishing anything', function (): void {
    fakePublishBraintrust();

    $this->artisan('ai:publish-eval', ['--target' => 'js-stub', '--scorers' => 'my_check', '--sample' => 'abc'])
        ->assertFailed();

    $this->artisan('ai:publish-eval', ['--target' => 'js-stub', '--scorers' => 'my_check', '--sample' => '1.5'])
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v1/function'));
});

it('aborts when the sandbox smoke test returns no score', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/function?*' => Http::response(['objects' => []]),
        'api.braintrust.dev/v1/function/fn-1/invoke' => Http::response(['error' => 'handler is not a function']),
        'api.braintrust.dev/v1/function' => Http::response(['id' => 'fn-1']),
    ]);

    $this->artisan('ai:publish-eval', ['--target' => 'js-stub', '--scorers' => 'my_check', '--sample' => '1'])
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v1/project_score'));
});

<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Middleware\LogAiResponse;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\PendingAiResponseLogs;
use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function makeLoggedAgent(array $middleware = [new LogAiResponse], ?array $loggableProperties = null): Agent
{
    if ($loggableProperties === null) {
        return new class($middleware) extends StubAgent implements HasMiddleware
        {
            public function __construct(private readonly array $pipes) {}

            public function middleware(): array
            {
                return $this->pipes;
            }
        };
    }

    return new class($middleware, $loggableProperties) extends StubAgent implements HasLoggableProperties, HasMiddleware
    {
        public function __construct(private readonly array $pipes, private readonly array $properties) {}

        public function middleware(): array
        {
            return $this->pipes;
        }

        public function loggableProperties(): array
        {
            return $this->properties;
        }
    };
}

function makeLoggedPrompt(?Agent $agent = null, string $promptText = 'Hello', string $invocationId = 'inv-1'): AgentPrompt
{
    return new AgentPrompt(
        agent: $agent ?? makeLoggedAgent(),
        prompt: $promptText,
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
        invocationId: $invocationId,
    );
}

function makeLoggedResponse(string $invocationId = 'inv-1', string $text = 'Hi there'): AgentResponse
{
    return new AgentResponse(
        invocationId: $invocationId,
        text: $text,
        usage: new TextUsage(inputTokens: 10, outputTokens: 5),
        meta: new Meta(provider: 'anthropic', model: 'claude-haiku-4-5-20251001'),
    );
}

function runLoggedPrompt(AgentPrompt $prompt, AgentResponse $response): void
{
    event(new PromptingAgent($response->invocationId, $prompt));
    event(new AgentPrompted($response->invocationId, $prompt, $response));
}

afterEach(function () {
    Context::forget('ai_usage_source_id');
    Context::forget('ai_usage_source_model');
});

it('logs a successful text response', function () {
    runLoggedPrompt(makeLoggedPrompt(promptText: 'Hello'), makeLoggedResponse());

    $log = AiResponseLog::sole();
    expect($log->invocation_id)->toBe('inv-1')
        ->and($log->prompt)->toBe('Hello')
        ->and($log->response)->toBe(['text' => 'Hi there'])
        ->and($log->status)->toBe(AiResponseStatus::Success)
        ->and($log->metadata)->toMatchArray(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001'])
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('opts in an agent that lists the middleware by class name', function () {
    runLoggedPrompt(makeLoggedPrompt(makeLoggedAgent([LogAiResponse::class])), makeLoggedResponse());

    expect(AiResponseLog::sole()->status)->toBe(AiResponseStatus::Success);
});

it('does not log agents that did not opt in', function (Agent $agent) {
    runLoggedPrompt(makeLoggedPrompt($agent), makeLoggedResponse());

    expect(AiResponseLog::count())->toBe(0);
})->with([
    'no middleware contract' => fn () => new StubAgent,
    'other middleware only' => fn () => makeLoggedAgent([fn ($step, $next) => $next($step)]),
]);

it('writes a running row with the invocation id before the run completes', function () {
    event(new PromptingAgent('inv-running', makeLoggedPrompt(promptText: 'mid-flight', invocationId: 'inv-running')));

    $log = AiResponseLog::sole();
    expect($log->status)->toBe(AiResponseStatus::Running)
        ->and($log->invocation_id)->toBe('inv-running')
        ->and($log->prompt)->toBe('mid-flight')
        ->and(app(PendingAiResponseLogs::class)->get('inv-running'))->toBe($log->id);
});

it('keeps one row per run when failover re-fires PromptingAgent', function () {
    $prompt = makeLoggedPrompt();

    event(new PromptingAgent('inv-1', $prompt));
    event(new PromptingAgent('inv-1', $prompt));
    event(new AgentPrompted('inv-1', $prompt, makeLoggedResponse()));

    expect(AiResponseLog::sole()->status)->toBe(AiResponseStatus::Success)
        ->and(app(PendingAiResponseLogs::class)->get('inv-1'))->toBeNull();
});

it('stores the invocation id as the feedback span when no context source is set', function () {
    runLoggedPrompt(makeLoggedPrompt(invocationId: 'inv-no-source'), makeLoggedResponse('inv-no-source'));

    expect(AiResponseLog::sole()->feedback_span_id)->toBe('inv-no-source');
});

it('stores the source-keyed root span as the feedback span when a context source is set', function () {
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');
    Context::add('ai_usage_source_id', 'session-9');

    runLoggedPrompt(makeLoggedPrompt(), makeLoggedResponse());

    expect(AiResponseLog::sole()->feedback_span_id)
        ->toBe(SpanBuilder::rootSpanId('App\Models\OnboardingSession', 'session-9'));
});

it('stores structured response payloads as JSON', function () {
    runLoggedPrompt(makeLoggedPrompt(), new StructuredAgentResponse(
        invocationId: 'inv-1',
        structured: ['result' => 'ok', 'items' => [1, 2, 3]],
        text: '{"result":"ok"}',
        usage: new TextUsage,
        meta: new Meta,
    ));

    expect(AiResponseLog::sole()->response)->toBe(['result' => 'ok', 'items' => [1, 2, 3]]);
});

it('records loggable properties when the agent implements the contract', function () {
    $agent = makeLoggedAgent(loggableProperties: ['company_id' => 42, 'user_id' => 7]);

    runLoggedPrompt(makeLoggedPrompt($agent), makeLoggedResponse());

    expect(AiResponseLog::sole()->properties)->toBe(['company_id' => 42, 'user_id' => 7]);
});

it('marks the row as failed when the run fails', function () {
    $prompt = makeLoggedPrompt();

    event(new PromptingAgent('inv-1', $prompt));
    event(new AgentFailed('inv-1', $prompt, new RuntimeException('boom')));

    $log = AiResponseLog::sole();
    expect($log->status)->toBe(AiResponseStatus::Failure)
        ->and($log->invocation_id)->toBe('inv-1')
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and(app(PendingAiResponseLogs::class)->get('inv-1'))->toBeNull();
});

it('ignores completion events for runs it did not start a row for', function () {
    $prompt = makeLoggedPrompt();

    event(new AgentPrompted('inv-1', $prompt, makeLoggedResponse()));
    event(new AgentFailed('inv-1', $prompt, new RuntimeException('boom')));

    expect(AiResponseLog::count())->toBe(0);
});

it('passes each generation step straight through', function () {
    $result = makeStepResult();

    expect((new LogAiResponse)->handle(makePendingStep(), fn () => $result))->toBe($result);
});

it('logs one row for a real run through the SDK pipeline', function () {
    $agent = new class implements Agent, HasMiddleware
    {
        use Promptable;

        public int $steps = 0;

        public function instructions(): string
        {
            return 'You are a test agent.';
        }

        public function middleware(): array
        {
            return [
                new LogAiResponse,
                function ($step, $next) {
                    $this->steps++;

                    return $next($step);
                },
            ];
        }
    };

    $agent::fake(['Hi there']);

    $response = $agent->prompt('Hello');

    expect($agent->steps)->toBeGreaterThanOrEqual(1);

    $log = AiResponseLog::sole();
    expect($log->invocation_id)->toBe($response->invocationId)
        ->and($log->agent)->toBe($agent::class)
        ->and($log->prompt)->toBe('Hello')
        ->and($log->response)->toBe(['text' => 'Hi there'])
        ->and($log->status)->toBe(AiResponseStatus::Success);
});

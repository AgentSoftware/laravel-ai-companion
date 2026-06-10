# Braintrust Exporter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship every Laravel AI SDK agent interaction to Braintrust as trace trees (tokens, timing, tools, errors), via a swappable `TraceExporter` contract, with zero changes required in consuming apps.

**Architecture:** Listeners on `laravel/ai` lifecycle events build neutral span arrays (`SpanBuilder`), grouped into traces by the `ai_usage_source_*` Laravel `Context` values the package already uses. A queued `ShipSpans` job hands batches to the container-bound `TraceExporter` contract; `BraintrustExporter` is the only Braintrust-aware class (maps neutral spans to Braintrust event format, resolves/caches the project ID, posts to `/v1/project_logs/{id}/insert`).

**Tech Stack:** PHP 8.4, Laravel package (spatie/laravel-package-tools), laravel/ai ^0.7 events, Pest 4 + Orchestra Testbench, `Http`/`Queue`/`Cache` facades.

**Spec:** `docs/superpowers/specs/2026-06-10-braintrust-exporter-design.md`

---

## File structure

```
config/ai-companion.php                          # add 'braintrust' section
src/Tracing/Contracts/TraceExporter.php          # the swap point
src/Tracing/TraceTimings.php                     # singleton: start times + pending failovers
src/Tracing/SpanBuilder.php                      # events -> neutral span arrays (pure, no IO)
src/Tracing/Listeners/ExportTrace.php            # event subscriber, dispatches ShipSpans
src/Tracing/Jobs/ShipSpans.php                   # queued, plain arrays, resolves TraceExporter
src/Tracing/Exporters/BraintrustExporter.php     # Braintrust mapping + HTTP
src/Middleware/TraceAiResponse.php               # opt-in hard-failure capture
src/LaravelAiCompanionServiceProvider.php        # bindings + conditional Event::subscribe
tests/Feature/Tracing/SpanBuilderTest.php
tests/Feature/Tracing/ShipSpansTest.php
tests/Feature/Tracing/BraintrustExporterTest.php
tests/Feature/Tracing/ExportTraceTest.php
tests/Feature/TraceAiResponseTest.php
README.md                                        # new Braintrust section
```

### The neutral span shape (used everywhere upstream of the exporter)

```php
[
    'id' => 'string',          // stable span id (Braintrust upserts by id)
    'trace_id' => 'string',    // root span id of the trace
    'parent_id' => null,       // parent span id, null for the root span
    'name' => 'string',        // display name
    'type' => 'llm',           // 'llm' | 'tool' | 'task'
    'input' => mixed,          // prompt / arguments
    'output' => mixed,         // response text / structured array / tool result
    'error' => null,           // string when errored
    'metadata' => [],          // agent, model, provider, app, environment, source_model, source_id
    'metrics' => [             // numeric only; nulls stripped by the exporter
        'start' => 1718000000.123,
        'end' => 1718000003.456,
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'tokens' => 150,
        'cache_write_tokens' => 10,
        'cache_read_tokens' => 5,
        'reasoning_tokens' => 0,
    ],
]
```

Braintrust mapping (only inside `BraintrustExporter`): `id`→`id`+`span_id`, `trace_id`→`root_span_id`, `parent_id`→`span_parents: [parent]`, `name`/`type`→`span_attributes`. Confirmed against the braintrust-openapi spec: metrics use `start`/`end` (unix seconds, float) and `prompt_tokens`/`completion_tokens`/`tokens`; extra numeric keys (our cache/reasoning tokens) are allowed as custom metrics; span linking uses `span_parents` (an array), **not** `parent_span_id`.

### Conventions reminder for the implementer

- Every PHP file: `declare(strict_types=1);` after `<?php`, classes follow existing package style (see `src/Listeners/RecordAgentTokenUsage.php`).
- Tests are Pest. **Helper functions in Pest test files are global** — `makeAgentPromptedEvent()` already exists in `RecordAgentTokenUsageTest.php`, so new helpers must use unique names (we use `makeTracingPromptedEvent()` etc.).
- Run tests with `composer test` (or `vendor/bin/pest`). Format with `vendor/bin/pint`. Static analysis: `vendor/bin/phpstan analyse`.
- Work in `/Users/ElliotPutt/code/laravel-ai-companion` on branch `braintrust-exporter`.

---

### Task 1: Config section + `TraceExporter` contract + `TraceTimings`

**Files:**
- Modify: `config/ai-companion.php`
- Create: `src/Tracing/Contracts/TraceExporter.php`
- Create: `src/Tracing/TraceTimings.php`
- Test: `tests/Feature/Tracing/TraceTimingsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tracing/TraceTimingsTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;

it('is a singleton', function () {
    expect(app(TraceTimings::class))->toBe(app(TraceTimings::class));
});

it('stores and pulls start times', function () {
    $timings = new TraceTimings;

    $timings->start('agent:abc', 123.45);

    expect($timings->pull('agent:abc'))->toBe(123.45)
        ->and($timings->pull('agent:abc'))->toBeNull();
});

it('returns null for unknown keys', function () {
    expect((new TraceTimings)->pull('missing'))->toBeNull();
});

it('collects and pulls pending failovers per agent class', function () {
    $timings = new TraceTimings;

    $timings->addFailover('App\Agents\Foo', ['provider' => 'OpenAi', 'model' => 'gpt-4.1', 'error' => 'boom']);
    $timings->addFailover('App\Agents\Foo', ['provider' => 'Groq', 'model' => 'llama', 'error' => 'down']);

    expect($timings->pullFailovers('App\Agents\Foo'))->toHaveCount(2)
        ->and($timings->pullFailovers('App\Agents\Foo'))->toBe([])
        ->and($timings->pullFailovers('App\Agents\Bar'))->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tracing/TraceTimingsTest.php`
Expected: FAIL — `Class "AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings" not found`

- [ ] **Step 3: Implement**

Create `src/Tracing/Contracts/TraceExporter.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Contracts;

interface TraceExporter
{
    /**
     * Whether this exporter is configured and able to ship spans.
     */
    public function enabled(): bool;

    /**
     * Ship a batch of neutral span arrays to the tracing backend.
     *
     * @param  array<int, array<string, mixed>>  $spans
     */
    public function ship(array $spans): void;
}
```

Create `src/Tracing/TraceTimings.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing;

class TraceTimings
{
    /** @var array<string, float> */
    private array $startTimes = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $failovers = [];

    public function start(string $key, float $time): void
    {
        $this->startTimes[$key] = $time;
    }

    public function pull(string $key): ?float
    {
        $time = $this->startTimes[$key] ?? null;

        unset($this->startTimes[$key]);

        return $time;
    }

    /**
     * @param  array<string, mixed>  $failover
     */
    public function addFailover(string $agentClass, array $failover): void
    {
        $this->failovers[$agentClass][] = $failover;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pullFailovers(string $agentClass): array
    {
        $failovers = $this->failovers[$agentClass] ?? [];

        unset($this->failovers[$agentClass]);

        return $failovers;
    }
}
```

In `config/ai-companion.php`, add the `braintrust` key after `response_logs`:

```php
'braintrust' => [
    'enabled' => env('AI_COMPANION_BRAINTRUST_ENABLED', false),
    'api_key' => env('BRAINTRUST_API_KEY'),
    'api_url' => env('BRAINTRUST_API_URL', 'https://api.braintrust.dev'),
    // Braintrust project name. Defaults to the app name at runtime when null.
    'project' => env('BRAINTRUST_PROJECT'),
    'queue' => [
        'connection' => env('AI_COMPANION_BRAINTRUST_QUEUE_CONNECTION'),
        'queue' => env('AI_COMPANION_BRAINTRUST_QUEUE'),
    ],
],
```

In `src/LaravelAiCompanionServiceProvider.php`, add to `packageBooted()` (after the existing singleton):

```php
$this->app->singleton(\AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings::class);
```

(Use a proper `use` import, matching file style.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tracing/TraceTimingsTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add config/ai-companion.php src/Tracing/ src/LaravelAiCompanionServiceProvider.php tests/Feature/Tracing/TraceTimingsTest.php
git commit -m "feat: add braintrust config, TraceExporter contract and TraceTimings"
```

---

### Task 2: `SpanBuilder`

**Files:**
- Create: `src/Tracing/SpanBuilder.php`
- Test: `tests/Feature/Tracing/SpanBuilderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tracing/SpanBuilderTest.php`. Note the unique helper names (Pest helpers are global):

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

function makeTracingAgent(): Agent
{
    return new class implements Agent
    {
        public function instructions(): string
        {
            return 'You are a test agent.';
        }

        public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }
    };
}

function makeTracingPromptedEvent(string $invocationId = 'inv-1'): AgentPrompted
{
    $response = new AgentResponse(
        invocationId: $invocationId,
        text: 'World',
        usage: new Usage(
            promptTokens: 100,
            completionTokens: 50,
            cacheWriteInputTokens: 10,
            cacheReadInputTokens: 5,
        ),
        meta: new Meta(provider: 'anthropic', model: 'claude-haiku-4-5-20251001'),
    );

    $prompt = new AgentPrompt(
        agent: makeTracingAgent(),
        prompt: 'Hello',
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
        invocationId: $invocationId,
    );

    return new AgentPrompted(invocationId: $invocationId, prompt: $prompt, response: $response);
}

afterEach(function () {
    Context::forget('ai_usage_source_id');
    Context::forget('ai_usage_source_model');
});

it('builds an agent span without source context as its own trace root', function () {
    $span = app(SpanBuilder::class)->agentSpan(makeTracingPromptedEvent(), 100.0, 103.5);

    expect($span['id'])->toBe('inv-1')
        ->and($span['trace_id'])->toBe('inv-1')
        ->and($span['parent_id'])->toBeNull()
        ->and($span['type'])->toBe('llm')
        ->and($span['input'])->toBe(['prompt' => 'Hello', 'instructions' => 'You are a test agent.'])
        ->and($span['output'])->toBe('World')
        ->and($span['error'])->toBeNull()
        ->and($span['metadata']['model'])->toBe('claude-haiku-4-5-20251001')
        ->and($span['metadata']['provider'])->toBe('anthropic')
        ->and($span['metrics'])->toBe([
            'start' => 100.0,
            'end' => 103.5,
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'tokens' => 150,
            'cache_write_tokens' => 10,
            'cache_read_tokens' => 5,
            'reasoning_tokens' => 0,
        ]);
});

it('parents agent spans under a deterministic root when source context is set', function () {
    Context::add('ai_usage_source_id', 'session-9');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    $builder = app(SpanBuilder::class);
    $span = $builder->agentSpan(makeTracingPromptedEvent(), 100.0, 101.0);
    $root = $builder->rootSpan();

    expect($root)->not->toBeNull()
        ->and($span['parent_id'])->toBe($root['id'])
        ->and($span['trace_id'])->toBe($root['id'])
        ->and($root['trace_id'])->toBe($root['id'])
        ->and($root['parent_id'])->toBeNull()
        ->and($root['name'])->toBe('OnboardingSession')
        ->and($root['type'])->toBe('task')
        ->and($root['metadata']['source_id'])->toBe('session-9');

    // Deterministic: same source always produces the same root id.
    expect($builder->rootSpan()['id'])->toBe($root['id']);
});

it('returns no root span without source context', function () {
    expect(app(SpanBuilder::class)->rootSpan())->toBeNull();
});

it('attaches failover metadata to agent spans', function () {
    $failovers = [['provider' => 'OpenAi', 'model' => 'gpt-4.1', 'error' => 'rate limited']];

    $span = app(SpanBuilder::class)->agentSpan(makeTracingPromptedEvent(), 100.0, 101.0, $failovers);

    expect($span['metadata']['failovers'])->toBe($failovers);
});

it('builds a tool span parented to its agent invocation', function () {
    Context::add('ai_usage_source_id', 'session-9');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'A test tool.';
        }

        public function handle(): string
        {
            return 'result';
        }
    };

    $event = new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-7',
        agent: makeTracingAgent(),
        tool: $tool,
        arguments: ['query' => 'homes'],
        result: 'found 3',
    );

    $builder = app(SpanBuilder::class);
    $span = $builder->toolSpan($event, 100.0, 100.4);

    expect($span['id'])->toBe('tool-7')
        ->and($span['parent_id'])->toBe('inv-1')
        ->and($span['trace_id'])->toBe($builder->rootSpan()['id'])
        ->and($span['type'])->toBe('tool')
        ->and($span['input'])->toBe(['query' => 'homes'])
        ->and($span['output'])->toBe('found 3')
        ->and($span['metrics']['start'])->toBe(100.0)
        ->and($span['metrics']['end'])->toBe(100.4);
});
```

> If `Laravel\Ai\Contracts\Tool` has a different method list in the installed version, check `vendor/laravel/ai/src/Contracts/Tool.php` and implement whatever it requires — the test only cares about the event payload.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tracing/SpanBuilderTest.php`
Expected: FAIL — `Class "AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder" not found`

- [ ] **Step 3: Implement**

Create `src/Tracing/SpanBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Ramsey\Uuid\Uuid;

class SpanBuilder
{
    /**
     * Build the span for a completed agent invocation.
     *
     * @param  array<int, array<string, mixed>>  $failovers
     * @return array<string, mixed>
     */
    public function agentSpan(AgentPrompted $event, ?float $startedAt, float $endedAt, array $failovers = []): array
    {
        $rootId = $this->rootId();
        $usage = $event->response->usage;

        return [
            'id' => $event->invocationId,
            'trace_id' => $rootId ?? $event->invocationId,
            'parent_id' => $rootId,
            'name' => class_basename($event->prompt->agent),
            'type' => 'llm',
            'input' => [
                'prompt' => $event->prompt->prompt,
                'instructions' => rescue(fn (): string => $event->prompt->agent->instructions(), null, false),
            ],
            'output' => $event->response instanceof StructuredAgentResponse
                ? $event->response->toArray()
                : $event->response->text,
            'error' => null,
            'metadata' => array_merge($this->baseMetadata(), array_filter([
                'agent' => $event->prompt->agent::class,
                'model' => $event->response->meta->model ?? $event->prompt->model,
                'provider' => $event->response->meta->provider,
                'failovers' => $failovers !== [] ? $failovers : null,
            ])),
            'metrics' => [
                'start' => $startedAt,
                'end' => $endedAt,
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'tokens' => $usage->promptTokens + $usage->completionTokens,
                'cache_write_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
            ],
        ];
    }

    /**
     * Build the span for a completed tool invocation.
     *
     * @return array<string, mixed>
     */
    public function toolSpan(ToolInvoked $event, ?float $startedAt, float $endedAt): array
    {
        return [
            'id' => $event->toolInvocationId,
            'trace_id' => $this->rootId() ?? $event->invocationId,
            'parent_id' => $event->invocationId,
            'name' => class_basename($event->tool),
            'type' => 'tool',
            'input' => $event->arguments,
            'output' => $event->result,
            'error' => null,
            'metadata' => array_merge($this->baseMetadata(), [
                'agent' => $event->agent::class,
                'tool' => $event->tool::class,
            ]),
            'metrics' => [
                'start' => $startedAt,
                'end' => $endedAt,
            ],
        ];
    }

    /**
     * Build the trace root span for the current business source, if any.
     *
     * Deterministic id means every listener can upsert it; the backend
     * merges events that share an id.
     *
     * @return array<string, mixed>|null
     */
    public function rootSpan(): ?array
    {
        $rootId = $this->rootId();

        if ($rootId === null) {
            return null;
        }

        return [
            'id' => $rootId,
            'trace_id' => $rootId,
            'parent_id' => null,
            'name' => class_basename((string) Context::get('ai_usage_source_model')),
            'type' => 'task',
            'input' => null,
            'output' => null,
            'error' => null,
            'metadata' => $this->baseMetadata(),
            'metrics' => [],
        ];
    }

    private function rootId(): ?string
    {
        $sourceId = Context::get('ai_usage_source_id');
        $sourceModel = Context::get('ai_usage_source_model');

        if (blank($sourceId) || blank($sourceModel)) {
            return null;
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, "ai-companion:{$sourceModel}:{$sourceId}")->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseMetadata(): array
    {
        return array_filter([
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'source_model' => Context::get('ai_usage_source_model'),
            'source_id' => Context::get('ai_usage_source_id'),
        ]);
    }
}
```

> `rescue(..., null, false)` keeps `instructions()` from ever breaking span building if an agent's instructions method needs runtime state.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tracing/SpanBuilderTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Tracing/SpanBuilder.php tests/Feature/Tracing/SpanBuilderTest.php
git commit -m "feat: add SpanBuilder mapping ai events to neutral spans"
```

---

### Task 3: `ShipSpans` job (exporter contract consumer)

**Files:**
- Create: `src/Tracing/Jobs/ShipSpans.php`
- Test: `tests/Feature/Tracing/ShipSpansTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tracing/ShipSpansTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;

class FakeTraceExporter implements TraceExporter
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $batches = [];

    public bool $isEnabled = true;

    public function enabled(): bool
    {
        return $this->isEnabled;
    }

    public function ship(array $spans): void
    {
        $this->batches[] = $spans;
    }
}

it('hands its spans to the bound TraceExporter', function () {
    $fake = new FakeTraceExporter;
    app()->instance(TraceExporter::class, $fake);

    $spans = [['id' => 'span-1', 'trace_id' => 'span-1']];

    (new ShipSpans($spans))->handle(app(TraceExporter::class));

    expect($fake->batches)->toBe([$spans]);
});

it('does nothing when the exporter is disabled', function () {
    $fake = new FakeTraceExporter;
    $fake->isEnabled = false;

    (new ShipSpans([['id' => 'span-1']]))->handle($fake);

    expect($fake->batches)->toBe([]);
});

it('uses the configured queue and connection', function () {
    config()->set('ai-companion.braintrust.queue.connection', 'redis');
    config()->set('ai-companion.braintrust.queue.queue', 'tracing');

    $job = new ShipSpans([]);

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('tracing');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tracing/ShipSpansTest.php`
Expected: FAIL — `Class "AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans" not found`

- [ ] **Step 3: Implement**

Create `src/Tracing/Jobs/ShipSpans.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Jobs;

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShipSpans implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<int, array<string, mixed>>  $spans
     */
    public function __construct(public array $spans)
    {
        $this->onConnection(config('ai-companion.braintrust.queue.connection'));
        $this->onQueue(config('ai-companion.braintrust.queue.queue'));
    }

    public function handle(TraceExporter $exporter): void
    {
        if (! $exporter->enabled()) {
            return;
        }

        $exporter->ship($this->spans);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('AI trace spans could not be shipped and were dropped.', [
            'spans' => count($this->spans),
            'exception' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tracing/ShipSpansTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Tracing/Jobs/ShipSpans.php tests/Feature/Tracing/ShipSpansTest.php
git commit -m "feat: add queued ShipSpans job consuming the TraceExporter contract"
```

---

### Task 4: `BraintrustExporter`

**Files:**
- Create: `src/Tracing/Exporters/BraintrustExporter.php`
- Modify: `src/LaravelAiCompanionServiceProvider.php` (bind contract)
- Test: `tests/Feature/Tracing/BraintrustExporterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tracing/BraintrustExporterTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Exporters\BraintrustExporter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai-companion.braintrust.enabled', true);
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'My Project');
});

function fakeBraintrustApi(): void
{
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/insert' => Http::response(['row_ids' => ['1']]),
    ]);
}

function neutralSpan(): array
{
    return [
        'id' => 'inv-1',
        'trace_id' => 'root-1',
        'parent_id' => 'root-1',
        'name' => 'ContentWriterAgent',
        'type' => 'llm',
        'input' => ['prompt' => 'Hello'],
        'output' => 'World',
        'error' => null,
        'metadata' => ['model' => 'claude-haiku-4-5-20251001'],
        'metrics' => ['start' => 1.0, 'end' => 2.0, 'prompt_tokens' => 10, 'completion_tokens' => 5, 'tokens' => 15, 'cache_write_tokens' => null, 'cache_read_tokens' => 0, 'reasoning_tokens' => 0],
    ];
}

it('is bound as the TraceExporter implementation', function () {
    expect(app(TraceExporter::class))->toBeInstanceOf(BraintrustExporter::class);
});

it('is enabled only with the flag and an api key', function () {
    expect(app(BraintrustExporter::class)->enabled())->toBeTrue();

    config()->set('ai-companion.braintrust.api_key', null);
    expect(app(BraintrustExporter::class)->enabled())->toBeFalse();

    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.enabled', false);
    expect(app(BraintrustExporter::class)->enabled())->toBeFalse();
});

it('ships spans mapped to braintrust insert events', function () {
    fakeBraintrustApi();

    app(BraintrustExporter::class)->ship([neutralSpan()]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/v1/project_logs/proj-123/insert')) {
            return false;
        }

        $event = $request->data()['events'][0];

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && $event['id'] === 'inv-1'
            && $event['span_id'] === 'inv-1'
            && $event['root_span_id'] === 'root-1'
            && $event['span_parents'] === ['root-1']
            && $event['span_attributes'] === ['name' => 'ContentWriterAgent', 'type' => 'llm']
            && $event['metrics'] === ['start' => 1.0, 'end' => 2.0, 'prompt_tokens' => 10, 'completion_tokens' => 5, 'tokens' => 15, 'cache_read_tokens' => 0, 'reasoning_tokens' => 0]
            && ! array_key_exists('error', $event);
    });
});

it('omits span_parents for root spans', function () {
    fakeBraintrustApi();

    $root = neutralSpan();
    $root['id'] = 'root-1';
    $root['parent_id'] = null;

    app(BraintrustExporter::class)->ship([$root]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/insert')) {
            return false;
        }

        return ! array_key_exists('span_parents', $request->data()['events'][0]);
    });
});

it('resolves the project id once and caches it', function () {
    fakeBraintrustApi();

    $exporter = app(BraintrustExporter::class);
    $exporter->ship([neutralSpan()]);
    $exporter->ship([neutralSpan()]);

    Http::assertSentCount(3); // 1 project resolution + 2 inserts

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/v1/project')
        || str_contains($request->url(), '/project_logs/')
        || $request->data() === ['name' => 'My Project']);
});

it('throws on http failure so the queued job retries', function () {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/insert' => Http::response(status: 500),
    ]);

    app(BraintrustExporter::class)->ship([neutralSpan()]);
})->throws(Illuminate\Http\Client\RequestException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tracing/BraintrustExporterTest.php`
Expected: FAIL — `Class ... BraintrustExporter" not found` (and the binding test fails)

- [ ] **Step 3: Implement**

Create `src/Tracing/Exporters/BraintrustExporter.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Exporters;

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BraintrustExporter implements TraceExporter
{
    public function enabled(): bool
    {
        return (bool) config('ai-companion.braintrust.enabled')
            && filled(config('ai-companion.braintrust.api_key'));
    }

    public function ship(array $spans): void
    {
        $this->client()
            ->post("/v1/project_logs/{$this->projectId()}/insert", [
                'events' => array_map($this->toBraintrustEvent(...), $spans),
            ])
            ->throw();
    }

    /**
     * Map a neutral span to a Braintrust insert event.
     *
     * @param  array<string, mixed>  $span
     * @return array<string, mixed>
     */
    private function toBraintrustEvent(array $span): array
    {
        return array_filter([
            'id' => $span['id'],
            'span_id' => $span['id'],
            'root_span_id' => $span['trace_id'],
            'span_parents' => filled($span['parent_id']) ? [$span['parent_id']] : null,
            'span_attributes' => [
                'name' => $span['name'],
                'type' => $span['type'],
            ],
            'input' => $span['input'],
            'output' => $span['output'],
            'error' => $span['error'],
            'metadata' => $span['metadata'],
            'metrics' => array_filter($span['metrics'], fn (mixed $value): bool => $value !== null),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Resolve the Braintrust project id for the configured project name.
     *
     * Braintrust's create endpoint returns the existing project unmodified
     * when one with the same name already exists, so this is a find-or-create.
     */
    private function projectId(): string
    {
        $project = config('ai-companion.braintrust.project') ?? config('app.name');

        return Cache::rememberForever(
            "ai-companion:braintrust:project-id:{$project}",
            fn (): string => $this->client()
                ->post('/v1/project', ['name' => $project])
                ->throw()
                ->json('id'),
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('ai-companion.braintrust.api_url'))
            ->withToken(config('ai-companion.braintrust.api_key'));
    }
}
```

In `src/LaravelAiCompanionServiceProvider.php`, add to `packageBooted()`:

```php
$this->app->bind(
    \AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter::class,
    \AgentSoftware\LaravelAiCompanion\Tracing\Exporters\BraintrustExporter::class,
);
```

(With proper `use` imports.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tracing/BraintrustExporterTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Tracing/Exporters/BraintrustExporter.php src/LaravelAiCompanionServiceProvider.php tests/Feature/Tracing/BraintrustExporterTest.php
git commit -m "feat: add BraintrustExporter implementing TraceExporter"
```

---

### Task 5: `ExportTrace` listener + event wiring

**Files:**
- Create: `src/Tracing/Listeners/ExportTrace.php`
- Modify: `src/LaravelAiCompanionServiceProvider.php` (conditional `Event::subscribe`)
- Test: `tests/Feature/Tracing/ExportTraceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tracing/ExportTraceTest.php`. It reuses `makeTracingPromptedEvent()`/`makeTracingAgent()` from `SpanBuilderTest.php` (Pest helpers are global across the suite):

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Provider;

function refreshTracingListeners(): void
{
    config()->set('ai-companion.braintrust.enabled', true);
    config()->set('ai-companion.braintrust.api_key', 'test-key');

    Event::subscribe(\AgentSoftware\LaravelAiCompanion\Tracing\Listeners\ExportTrace::class);
}

afterEach(function () {
    Context::forget('ai_usage_source_id');
    Context::forget('ai_usage_source_model');
});

it('ships an agent span with timing when a prompt completes', function () {
    refreshTracingListeners();
    Queue::fake();

    $prompted = makeTracingPromptedEvent('inv-42');

    event(new PromptingAgent(invocationId: 'inv-42', prompt: $prompted->prompt));
    event($prompted);

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        $span = collect($job->spans)->firstWhere('id', 'inv-42');

        return $span !== null
            && $span['type'] === 'llm'
            && $span['metrics']['start'] !== null
            && $span['metrics']['end'] >= $span['metrics']['start'];
    });
});

it('includes the root span in the batch when source context is set', function () {
    refreshTracingListeners();
    Queue::fake();

    Context::add('ai_usage_source_id', 'session-1');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    event(makeTracingPromptedEvent('inv-1'));

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        return count($job->spans) === 2
            && $job->spans[0]['type'] === 'task'
            && $job->spans[1]['parent_id'] === $job->spans[0]['id'];
    });
});

it('attaches failover details to the next span for that agent', function () {
    refreshTracingListeners();
    Queue::fake();

    $prompted = makeTracingPromptedEvent('inv-9');

    event(new AgentFailedOver(
        agent: $prompted->prompt->agent,
        provider: Mockery::mock(Provider::class),
        model: 'gpt-4.1',
        exception: Mockery::mock(FailoverableException::class, ['getMessage' => 'rate limited']),
    ));
    event($prompted);

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        $span = collect($job->spans)->firstWhere('id', 'inv-9');

        return $span['metadata']['failovers'][0]['model'] === 'gpt-4.1';
    });
});

it('does not ship anything when the response is streamed', function () {
    refreshTracingListeners();
    Queue::fake();

    // AgentPrompted with a StreamedAgentResponse is out of scope for v1.
    // Guarded by an instanceof check; nothing should be queued.
    $prompted = makeTracingPromptedEvent('inv-stream');
    $streamed = new AgentPrompted(
        invocationId: 'inv-stream',
        prompt: $prompted->prompt,
        response: Mockery::mock(Laravel\Ai\Responses\StreamedAgentResponse::class),
    );

    event($streamed);

    Queue::assertNothingPushed();
});

it('never throws even when span building fails', function () {
    refreshTracingListeners();
    Queue::fake();

    // ToolInvoked with no matching timing entry and odd data must not throw.
    event(new Laravel\Ai\Events\ToolInvoked(
        invocationId: 'inv-x',
        toolInvocationId: 'tool-x',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Laravel\Ai\Contracts\Tool::class),
        arguments: [],
        result: fopen('php://memory', 'r'), // non-serializable value
    ));

    expect(true)->toBeTrue();
});
```

> Adjust the `AgentFailedOver` / `FailoverableException` mocks to whatever the installed laravel/ai version's constructors require (check `vendor/laravel/ai/src/Events/AgentFailedOver.php` and `src/Exceptions/FailoverableException.php`). If `Provider` cannot be mocked directly (abstract/final), use any concrete provider class from `vendor/laravel/ai/src/Providers/`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tracing/ExportTraceTest.php`
Expected: FAIL — `Class ... ExportTrace" not found`

- [ ] **Step 3: Implement**

Create `src/Tracing/Listeners/ExportTrace.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Listeners;

use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\AgentResponse;

readonly class ExportTrace
{
    public function __construct(
        private TraceTimings $timings,
        private SpanBuilder $builder,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            PromptingAgent::class => 'handlePromptingAgent',
            AgentPrompted::class => 'handleAgentPrompted',
            InvokingTool::class => 'handleInvokingTool',
            ToolInvoked::class => 'handleToolInvoked',
            AgentFailedOver::class => 'handleAgentFailedOver',
        ];
    }

    public function handlePromptingAgent(PromptingAgent $event): void
    {
        rescue(fn () => $this->timings->start("agent:{$event->invocationId}", microtime(true)), report: false);
    }

    public function handleAgentPrompted(AgentPrompted $event): void
    {
        rescue(function () use ($event): void {
            if (! $event->response instanceof AgentResponse) {
                return;
            }

            $this->ship($this->builder->agentSpan(
                $event,
                $this->timings->pull("agent:{$event->invocationId}"),
                microtime(true),
                $this->timings->pullFailovers($event->prompt->agent::class),
            ));
        }, report: false);
    }

    public function handleInvokingTool(InvokingTool $event): void
    {
        rescue(fn () => $this->timings->start("tool:{$event->toolInvocationId}", microtime(true)), report: false);
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        rescue(function () use ($event): void {
            $this->ship($this->builder->toolSpan(
                $event,
                $this->timings->pull("tool:{$event->toolInvocationId}"),
                microtime(true),
            ));
        }, report: false);
    }

    public function handleAgentFailedOver(AgentFailedOver $event): void
    {
        rescue(function () use ($event): void {
            $this->timings->addFailover($event->agent::class, [
                'provider' => class_basename($event->provider),
                'model' => $event->model,
                'error' => $event->exception->getMessage(),
            ]);
        }, report: false);
    }

    /**
     * @param  array<string, mixed>  $span
     */
    private function ship(array $span): void
    {
        $spans = array_values(array_filter([$this->builder->rootSpan(), $span]));

        // Serialization guard: spans must survive the queue as plain data.
        json_encode($spans, JSON_THROW_ON_ERROR);

        ShipSpans::dispatch($spans);
    }
}
```

In `src/LaravelAiCompanionServiceProvider.php` `packageBooted()`, after the existing wiring:

```php
if (config('ai-companion.braintrust.enabled')) {
    Event::subscribe(\AgentSoftware\LaravelAiCompanion\Tracing\Listeners\ExportTrace::class);
}
```

(With proper `use` import. Note `rescue(..., report: false)` — if the installed Laravel version's `rescue` signature is `rescue($callback, $rescue = null, $report = true)`, call it as `rescue(fn () => ..., null, false)`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tracing/ExportTraceTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Run the whole suite (regression check)**

Run: `composer test`
Expected: all green, including the pre-existing token-usage and response-log tests.

- [ ] **Step 6: Commit**

```bash
git add src/Tracing/Listeners/ExportTrace.php src/LaravelAiCompanionServiceProvider.php tests/Feature/Tracing/ExportTraceTest.php
git commit -m "feat: wire ai sdk events to span export via ExportTrace subscriber"
```

---

### Task 6: `TraceAiResponse` middleware (hard-failure capture)

**Files:**
- Create: `src/Middleware/TraceAiResponse.php`
- Test: `tests/Feature/TraceAiResponseTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TraceAiResponseTest.php` (mirrors how `LogAiResponseTest.php` exercises `LogAiResponse` — check that file and reuse its prompt-construction approach; reuses global `makeTracingAgent()`):

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Middleware\TraceAiResponse;
use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;

function makeTracingFailurePrompt(): AgentPrompt
{
    return new AgentPrompt(
        agent: makeTracingAgent(),
        prompt: 'Hello',
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
    );
}

afterEach(function () {
    Context::forget('ai_usage_source_id');
    Context::forget('ai_usage_source_model');
});

it('ships an error span and rethrows when the agent call fails', function () {
    Queue::fake();
    Context::add('ai_usage_source_id', 'session-1');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    $middleware = app(TraceAiResponse::class);

    try {
        $middleware->handle(makeTracingFailurePrompt(), function (): never {
            throw new RuntimeException('provider exploded');
        });

        $this->fail('Exception was not rethrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('provider exploded');
    }

    Queue::assertPushed(ShipSpans::class, function (ShipSpans $job): bool {
        $span = collect($job->spans)->last();

        return $span['error'] === 'provider exploded'
            && $span['type'] === 'llm'
            && $span['parent_id'] !== null
            && $span['metrics']['end'] >= $span['metrics']['start'];
    });
});

it('passes successful responses through without shipping', function () {
    Queue::fake();

    $middleware = app(TraceAiResponse::class);
    $expected = new stdClass;

    $result = $middleware->handle(makeTracingFailurePrompt(), fn (): stdClass => $expected);

    expect($result)->toBe($expected);
    Queue::assertNothingPushed();
});
```

> Note: success spans are already shipped by the `AgentPrompted` listener — the middleware only handles the failure path, hence `assertNothingPushed` on success. If `LogAiResponse::handle()` declares an `AgentResponse` return type, match the middleware signature to whatever the installed SDK's middleware pipeline expects (check `vendor/laravel/ai/src/Middleware/`); relax the test's `stdClass` accordingly by returning a real `AgentResponse` built as in `makeTracingPromptedEvent()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/TraceAiResponseTest.php`
Expected: FAIL — `Class ... TraceAiResponse" not found`

- [ ] **Step 3: Implement**

Create `src/Middleware/TraceAiResponse.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Middleware;

use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

readonly class TraceAiResponse
{
    public function __construct(private SpanBuilder $builder) {}

    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        $startedAt = microtime(true);

        try {
            return $next($prompt);
        } catch (Throwable $exception) {
            rescue(function () use ($prompt, $exception, $startedAt): void {
                $this->shipErrorSpan($prompt, $exception, $startedAt);
            }, report: false);

            throw $exception;
        }
    }

    private function shipErrorSpan(AgentPrompt $prompt, Throwable $exception, float $startedAt): void
    {
        $root = $this->builder->rootSpan();
        $id = $prompt->invocationId ?? (string) Str::uuid();

        $span = [
            'id' => $id,
            'trace_id' => $root['id'] ?? $id,
            'parent_id' => $root['id'] ?? null,
            'name' => class_basename($prompt->agent),
            'type' => 'llm',
            'input' => ['prompt' => $prompt->prompt],
            'output' => null,
            'error' => $exception->getMessage(),
            'metadata' => [
                'agent' => $prompt->agent::class,
                'model' => $prompt->model,
                'exception' => $exception::class,
            ],
            'metrics' => [
                'start' => $startedAt,
                'end' => microtime(true),
            ],
        ];

        ShipSpans::dispatch(array_values(array_filter([$root, $span])));
    }
}
```

> Error spans go through `ShipSpans` directly (not via `SpanBuilder->agentSpan()`) because there is no response object on the failure path. If the spec's error metadata grows, extract an `errorSpan()` method onto `SpanBuilder` — not needed for v1.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/TraceAiResponseTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Middleware/TraceAiResponse.php tests/Feature/TraceAiResponseTest.php
git commit -m "feat: add TraceAiResponse middleware for hard-failure spans"
```

---

### Task 7: README + quality gate

**Files:**
- Modify: `README.md`
- No new tests.

- [ ] **Step 1: Document the feature**

Add a `## Braintrust tracing` section to `README.md` after the response-logging section, covering:

```markdown
## Braintrust tracing

Opt-in: ship every agent interaction to [Braintrust](https://www.braintrust.dev) as traces — tokens, latency, tool calls, failovers, and errors.

```dotenv
AI_COMPANION_BRAINTRUST_ENABLED=true
BRAINTRUST_API_KEY=sk-...
BRAINTRUST_PROJECT="My App"            # optional, defaults to app.name
AI_COMPANION_BRAINTRUST_QUEUE=tracing  # optional, keep export traffic off busy queues
```

Traces are grouped by the same `Context` source the token tracker uses: when
`ai_usage_source_id` / `ai_usage_source_model` are set (e.g. via a job middleware),
every agent call for that source lands in one Braintrust trace tree. Without a
source, each invocation is its own trace.

Spans ship via a queued job and the exporter never throws into your AI calls —
if Braintrust is down, spans are retried, then dropped with a log warning.

### Capturing hard failures

Add the opt-in middleware to an agent to record errored invocations:

```php
use AgentSoftware\LaravelAiCompanion\Middleware\TraceAiResponse;
use Laravel\Ai\Contracts\HasMiddleware;

class MyAgent implements Agent, HasMiddleware
{
    public function middleware(): array
    {
        return [TraceAiResponse::class];
    }
}
```

### Swapping the backend

All spans flow through the `TraceExporter` contract. Bind your own
implementation to switch operators without touching listeners:

```php
$this->app->bind(
    \AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter::class,
    MyCustomExporter::class,
);
```
```

(Verify the exact `HasMiddleware` interface/method name against `vendor/laravel/ai/src/Contracts` and the existing `LogAiResponse` README section — follow whatever that section shows.)

- [ ] **Step 2: Run the full quality gate**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse
composer test
```

Expected: Pint applies/confirms formatting, PHPStan reports no new errors, full suite green. Fix anything that surfaces.

- [ ] **Step 3: Commit**

```bash
git add README.md
git add -u
git commit -m "docs: document braintrust tracing + quality pass"
```

---

## Self-review (completed)

- **Spec coverage:** config section (Task 1), contract + binding (Tasks 1, 4), trace model with deterministic roots/fallback (Task 2), tool child spans (Tasks 2, 5), timing singleton (Task 1), queued shipping with retry/log-drop (Task 3), Braintrust mapping + project-id caching (Task 4), failover capture (Task 5), hard-failure middleware (Task 6), contract-swap test (Task 3 fake), docs (Task 7). Out-of-scope items from the spec have no tasks, as intended.
- **Placeholder scan:** all steps contain complete code; the only conditional notes are version-pinning checks against the installed `laravel/ai` vendor code, with exact file paths to check.
- **Type consistency:** neutral span keys (`id`, `trace_id`, `parent_id`, `name`, `type`, `input`, `output`, `error`, `metadata`, `metrics`) are identical across `SpanBuilder`, `ShipSpans`, `BraintrustExporter`, and `TraceAiResponse`. `TraceExporter` methods (`enabled()`, `ship(array)`) match in contract, fake, exporter, and job.

# AI Tool Call Logging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record every tool invocation (name, input, output, duration) in a new `ai_tool_calls` table, hard-linked to the `ai_response_logs` row for that agent invocation, gated behind an opt-in config flag.

**Architecture:** A new event subscriber `RecordAiToolCall` listens to `laravel/ai`'s `InvokingTool` (start timer) and `ToolInvoked` (look up the parent `AiResponseLog` by `invocation_id`, write a row with duration). It's registered in the service provider only when `ai-companion.tool_call_logs.enabled` is true, mirroring the existing `braintrust.enabled` gate for `ExportTrace`. Timing reuses the existing `TraceTimings` singleton (already used by `ExportTrace` for the same event pair) under a distinct key prefix so the two subscribers don't collide when both are enabled.

**Tech Stack:** Laravel 11/12, `laravel/ai` events, Eloquent (UUID PK models), Pest 4 feature tests.

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- Listeners are `readonly` classes.
- Listener logic must never throw into an AI call — wrap in `rescue()`, matching `ExportTrace` and `RecordAgentTokenUsage`'s pattern.
- Migration naming: `YYYY_MM_DD_NNNNNN_<description>.php` in `database/migrations/`, anonymous class extending `Migration`.
- Run `vendor/bin/pint --dirty` before committing; `vendor/bin/pest` and `vendor/bin/phpstan analyse` must pass.
- `TraceTimings` keys already in use: `"agent:{invocationId}"` and `"tool:{toolInvocationId}"` (by `ExportTrace`). This plan's listener must use a non-colliding key prefix: `"tool_call:{toolInvocationId}"`.

---

### Task 1: `ai_tool_calls` migration + `AiToolCall` model

**Files:**
- Create: `database/migrations/2026_07_07_000001_create_ai_tool_calls_table.php`
- Create: `src/Models/AiToolCall.php`
- Modify: `src/Models/AiResponseLog.php`
- Test: `tests/Feature/Models/AiToolCallTest.php`

**Interfaces:**
- Produces: `AiToolCall` model with fillable `ai_response_log_id`, `tool_invocation_id`, `tool`, `input` (array), `output` (array, nullable), `duration_ms` (int, nullable); `belongsTo(AiResponseLog::class)` via `responseLog()`.
- Produces: `AiResponseLog::toolCalls(): HasMany<AiToolCall>`.

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_response_log_id')
                ->constrained('ai_response_logs')
                ->cascadeOnDelete();
            $table->string('tool_invocation_id')->nullable()->unique();
            $table->string('tool')->index();
            $table->json('input');
            $table->json('output')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
    }
};
```

- [ ] **Step 2: Write the `AiToolCall` model**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ai_response_log_id
 * @property string|null $tool_invocation_id
 * @property string $tool
 * @property array<string, mixed> $input
 * @property mixed $output
 * @property int|null $duration_ms
 */
class AiToolCall extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_response_log_id',
        'tool_invocation_id',
        'tool',
        'input',
        'output',
        'duration_ms',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
    ];

    /** @return BelongsTo<AiResponseLog, $this> */
    public function responseLog(): BelongsTo
    {
        return $this->belongsTo(AiResponseLog::class);
    }
}
```

- [ ] **Step 3: Add the inverse relation to `AiResponseLog`**

In `src/Models/AiResponseLog.php`, add the import and method:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

```php
    /** @return HasMany<AiToolCall, $this> */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AiToolCall::class);
    }
```

(`AiToolCall` is in the same `Models` namespace, no new import needed.)

- [ ] **Step 4: Write the model test**

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Models\AiToolCall;

it('belongs to a response log and casts input/output to arrays', function () {
    $log = AiResponseLog::create([
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    $call = AiToolCall::create([
        'ai_response_log_id' => $log->id,
        'tool_invocation_id' => 'tool-1',
        'tool' => 'App\\Tools\\SearchTool',
        'input' => ['query' => 'x'],
        'output' => ['results' => []],
        'duration_ms' => 42,
    ]);

    expect($call->input)->toBe(['query' => 'x'])
        ->and($call->output)->toBe(['results' => []])
        ->and($call->responseLog->is($log))->toBeTrue()
        ->and($log->fresh()->toolCalls->first()->is($call))->toBeTrue();
});

it('cascades delete from the parent response log', function () {
    $log = AiResponseLog::create([
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    AiToolCall::create([
        'ai_response_log_id' => $log->id,
        'tool' => 'App\\Tools\\SearchTool',
        'input' => [],
    ]);

    $log->delete();

    expect(AiToolCall::count())->toBe(0);
});
```

Check `AiResponseStatus` enum's `Success` case name first:

```bash
cat src/Enums/AiResponseStatus.php
```

Adjust the case name in the test if it differs from `Success`.

- [ ] **Step 5: Run the tests, verify they pass**

Run: `vendor/bin/pest tests/Feature/Models/AiToolCallTest.php`
Expected: 2 passed

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_07_07_000001_create_ai_tool_calls_table.php src/Models/AiToolCall.php src/Models/AiResponseLog.php tests/Feature/Models/AiToolCallTest.php
git commit -m "feat: add ai_tool_calls table and AiToolCall model"
```

---

### Task 2: Config flag

**Files:**
- Modify: `config/ai-companion.php`
- Test: `tests/Feature/Config/ToolCallLogsConfigTest.php`

**Interfaces:**
- Produces: `config('ai-companion.tool_call_logs.enabled')` (bool, default `false`, env `AI_COMPANION_TOOL_CALL_LOGS_ENABLED`).

- [ ] **Step 1: Add the config block**

In `config/ai-companion.php`, add after the `'braintrust'` block (before `'eval'`):

```php
    'tool_call_logs' => [
        'enabled' => env('AI_COMPANION_TOOL_CALL_LOGS_ENABLED', false),
    ],

```

- [ ] **Step 2: Write a config default test**

```php
<?php

declare(strict_types=1);

it('defaults tool call logging to disabled', function () {
    expect(config('ai-companion.tool_call_logs.enabled'))->toBeFalse();
});
```

- [ ] **Step 3: Run the test**

Run: `vendor/bin/pest tests/Feature/Config/ToolCallLogsConfigTest.php`
Expected: 1 passed

- [ ] **Step 4: Commit**

```bash
git add config/ai-companion.php tests/Feature/Config/ToolCallLogsConfigTest.php
git commit -m "feat: add tool_call_logs.enabled config flag"
```

---

### Task 3: `RecordAiToolCall` listener

**Files:**
- Create: `src/Listeners/RecordAiToolCall.php`
- Test: `tests/Feature/RecordAiToolCallTest.php`

**Interfaces:**
- Consumes: `AiToolCall::create(array)` (Task 1), `AiResponseLog` query by `invocation_id` (Task 1), `TraceTimings::start(string, float): void` / `pull(string): ?float` (existing), `Laravel\Ai\Events\InvokingTool` (`invocationId`, `toolInvocationId`, `agent`, `tool`, `arguments`), `Laravel\Ai\Events\ToolInvoked` (same fields plus `result`).
- Produces: `RecordAiToolCall` with a `subscribe(): array` method (event subscriber, same shape as `ExportTrace::subscribe()`) mapping `InvokingTool::class => 'handleInvokingTool'` and `ToolInvoked::class => 'handleToolInvoked'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Listeners\RecordAiToolCall;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Models\AiToolCall;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

function subscribeToolCallLogging(): void
{
    config()->set('ai-companion.tool_call_logs.enabled', true);

    Event::subscribe(RecordAiToolCall::class);
}

it('records a tool call linked to its response log', function () {
    subscribeToolCallLogging();

    $log = AiResponseLog::create([
        'invocation_id' => 'inv-1',
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    event(new InvokingTool(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-1',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: ['q' => 'x'],
    ));
    event(new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-1',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: ['q' => 'x'],
        result: 'ok',
    ));

    expect(AiToolCall::count())->toBe(1);

    $call = AiToolCall::first();
    expect($call->ai_response_log_id)->toBe($log->id)
        ->and($call->tool_invocation_id)->toBe('tool-1')
        ->and($call->input)->toBe(['q' => 'x'])
        ->and($call->output)->toBe('ok')
        ->and($call->duration_ms)->toBeInt();
});

it('skips silently when no matching response log exists', function () {
    subscribeToolCallLogging();

    event(new ToolInvoked(
        invocationId: 'inv-missing',
        toolInvocationId: 'tool-missing',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: [],
        result: null,
    ));

    expect(AiToolCall::count())->toBe(0);
});

it('never throws when tool call recording fails', function () {
    subscribeToolCallLogging();

    $log = AiResponseLog::create([
        'invocation_id' => 'inv-x',
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    // Pre-existing row with the same tool_invocation_id trips the unique
    // constraint, forcing the listener's create() to throw internally.
    AiToolCall::create([
        'ai_response_log_id' => $log->id,
        'tool_invocation_id' => 'tool-dupe',
        'tool' => 'App\\Tools\\SearchTool',
        'input' => [],
    ]);

    event(new ToolInvoked(
        invocationId: 'inv-x',
        toolInvocationId: 'tool-dupe',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: ['q' => 'y'],
        result: 'ok',
    ));

    expect(AiToolCall::count())->toBe(1);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/RecordAiToolCallTest.php`
Expected: FAIL — class `AgentSoftware\LaravelAiCompanion\Listeners\RecordAiToolCall` not found.

- [ ] **Step 3: Write the listener**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Listeners;

use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Models\AiToolCall;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

readonly class RecordAiToolCall
{
    public function __construct(
        private TraceTimings $timings,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            InvokingTool::class => 'handleInvokingTool',
            ToolInvoked::class => 'handleToolInvoked',
        ];
    }

    public function handleInvokingTool(InvokingTool $event): void
    {
        rescue(fn () => $this->timings->start("tool_call:{$event->toolInvocationId}", microtime(true)), null, false);
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        rescue(function () use ($event): void {
            $startedAt = $this->timings->pull("tool_call:{$event->toolInvocationId}");

            $log = AiResponseLog::where('invocation_id', $event->invocationId)->first();

            if ($log === null) {
                return;
            }

            $durationMs = $startedAt !== null
                ? (int) round((microtime(true) - $startedAt) * 1000)
                : null;

            AiToolCall::create([
                'ai_response_log_id' => $log->id,
                'tool_invocation_id' => $event->toolInvocationId,
                'tool' => $event->tool::class,
                'input' => $event->arguments,
                'output' => $event->result,
                'duration_ms' => $durationMs,
            ]);
        });
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `vendor/bin/pest tests/Feature/RecordAiToolCallTest.php`
Expected: 3 passed

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty
git add src/Listeners/RecordAiToolCall.php tests/Feature/RecordAiToolCallTest.php
git commit -m "feat: add RecordAiToolCall event subscriber"
```

---

### Task 4: Wire up the service provider

**Files:**
- Modify: `src/LaravelAiCompanionServiceProvider.php`
- Test: `tests/Feature/RecordAiToolCallTest.php` (append)

**Interfaces:**
- Consumes: `RecordAiToolCall` (Task 3), `config('ai-companion.tool_call_logs.enabled')` (Task 2).

- [ ] **Step 1: Register the subscriber conditionally**

In `src/LaravelAiCompanionServiceProvider.php`, add the import:

```php
use AgentSoftware\LaravelAiCompanion\Listeners\RecordAiToolCall;
```

In `packageBooted()`, immediately after the existing `if (config('ai-companion.braintrust.enabled')) { ... }` block, add:

```php
        if (config('ai-companion.tool_call_logs.enabled')) {
            Event::subscribe(RecordAiToolCall::class);
        }
```

- [ ] **Step 2: Write a test that the listener is NOT active without the flag**

Append to `tests/Feature/RecordAiToolCallTest.php`:

```php
it('does not record tool calls when the feature flag is disabled', function () {
    config()->set('ai-companion.tool_call_logs.enabled', false);

    $this->refreshApplication();

    AiResponseLog::create([
        'invocation_id' => 'inv-disabled',
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    event(new ToolInvoked(
        invocationId: 'inv-disabled',
        toolInvocationId: 'tool-disabled',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: [],
        result: 'ok',
    ));

    expect(AiToolCall::count())->toBe(0);
});
```

Check whether `tests/Support/TestCase.php` already sets `ai-companion.tool_call_logs.enabled` to false by default (it will, since Task 2's config default is `false` and the app boots fresh per test) — if `refreshApplication()` isn't available/needed in this TestCase, drop that line and rely on the fact that the provider's `packageBooted()` only runs once at boot, so instead assert this behavior by NOT calling `subscribeToolCallLogging()` in this test at all (default app boot already has the flag off):

```php
it('does not record tool calls when the feature flag is disabled', function () {
    AiResponseLog::create([
        'invocation_id' => 'inv-disabled',
        'agent' => 'App\\Agents\\ExampleAgent',
        'prompt' => 'hi',
        'status' => AiResponseStatus::Success,
    ]);

    event(new ToolInvoked(
        invocationId: 'inv-disabled',
        toolInvocationId: 'tool-disabled',
        agent: makeTracingAgent(),
        tool: Mockery::mock(Tool::class),
        arguments: [],
        result: 'ok',
    ));

    expect(AiToolCall::count())->toBe(0);
});
```

Use this second version (no `refreshApplication`) — it relies on the package's own boot-time gate rather than re-booting mid-test, which matches how `ExportTraceTest` never needs to toggle the flag off (each test file's tests run against a fresh app per Pest's default `RefreshDatabase`/app boot).

- [ ] **Step 3: Run the tests, verify they pass**

Run: `vendor/bin/pest tests/Feature/RecordAiToolCallTest.php`
Expected: 4 passed

- [ ] **Step 4: Run the full test suite and static analysis**

```bash
vendor/bin/pest
vendor/bin/phpstan analyse
```

Expected: all green.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty
git add src/LaravelAiCompanionServiceProvider.php tests/Feature/RecordAiToolCallTest.php
git commit -m "feat: register RecordAiToolCall subscriber behind tool_call_logs.enabled"
```

---

### Task 5: Document the feature

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:** None (docs only).

- [ ] **Step 1: Add a short section to CLAUDE.md**

In the "Project Overview" numbered list, add a 5th item after "Evaluations":

```
5. **Tool call logging** — opt-in `RecordAiToolCall` event subscriber → `ai_tool_calls` table, one row per `ToolInvoked` event, hard-linked via `ai_response_log_id` to the `ai_response_logs` row for that invocation. Gated by `tool_call_logs.enabled` config; silently no-ops if no matching response log exists (e.g. `LogAiResponse` middleware not active for that agent).
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document tool call logging feature"
```

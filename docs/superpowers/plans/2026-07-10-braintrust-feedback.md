# Braintrust User Feedback (thumbs up/down) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a consuming app record a thumbs up/down against a Braintrust-logged business flow (e.g. an onboarding session), by recomputing the same deterministic root span id the tracing pipeline already produces from `ai_usage_source_model`/`ai_usage_source_id`.

**Architecture:** A new `BraintrustFeedbackClient` (Braintrust-aware, used directly — not behind a swappable contract, mirroring the existing `BraintrustApi` precedent) POSTs to Braintrust's `/v1/project_logs/{project_id}/feedback` endpoint, targeting the row `id` that `SpanBuilder` already assigns to the root span. A new `AiFeedback` facade exposes `record()` to consuming apps. Two small extractions (`SpanBuilder::rootSpanId()`, an `InteractsWithBraintrustApi` trait) keep the id computation and HTTP/project-id/error-handling logic identical between the existing exporter tooling and this new client.

**Tech Stack:** PHP 8.4, Laravel `Http` facade, `ramsey/uuid`, Pest 4.

**Spec:** `docs/superpowers/specs/2026-07-10-braintrust-feedback-design.md`

## Global Constraints

- Every new PHP file: `declare(strict_types=1);`.
- Feedback attaches to the whole session (root span) only — no per-step targeting.
- The HTTP call is synchronous (direct request, not queued).
- Throws on misconfiguration (Braintrust disabled or no API key) and on HTTP failure — never silently no-ops.
- Score is fixed: `good: true` → `1.0`, `good: false` → `0.0`, under score name `user_feedback`. No configurable score name.
- No new config keys — reuses `ai-companion.braintrust.{enabled,api_key,api_url,project}`.
- Run `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse` before committing (per project CLAUDE.md).
- Tests use `Http::fake()` — never hit the real Braintrust API.

---

### Task 1: Extract a reusable, pure root-span-id computation on `SpanBuilder`

**Files:**
- Modify: `src/Tracing/SpanBuilder.php:155-165` (the private `rootId()` method)
- Test: `tests/Feature/Tracing/SpanBuilderTest.php`

**Interfaces:**
- Produces: `SpanBuilder::rootSpanId(string $sourceModel, string $sourceId): string` (new public static method) — later used by `BraintrustFeedbackClient` (Task 4) to compute the same id without needing `Context` to be set.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Tracing/SpanBuilderTest.php` (after the existing `it('parents agent spans under a deterministic root when source context is set', ...)` test):

```php
it('exposes a static root span id computation that matches the context-derived root', function () {
    Context::add('ai_usage_source_id', 'session-9');
    Context::add('ai_usage_source_model', 'App\Models\OnboardingSession');

    $builder = app(SpanBuilder::class);
    $root = $builder->rootSpan();

    expect(SpanBuilder::rootSpanId('App\Models\OnboardingSession', 'session-9'))->toBe($root['id']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest --filter "exposes a static root span id computation"`
Expected: FAIL with `Call to undefined method ... SpanBuilder::rootSpanId()`

- [ ] **Step 3: Extract the static method and delegate from the private one**

In `src/Tracing/SpanBuilder.php`, replace the existing `rootId()` method (lines 155-165) with:

```php
    public static function rootSpanId(string $sourceModel, string $sourceId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "ai-companion:{$sourceModel}:{$sourceId}")->toString();
    }

    private function rootId(): ?string
    {
        $sourceId = Context::get('ai_usage_source_id');
        $sourceModel = Context::get('ai_usage_source_model');

        if (blank($sourceId) || blank($sourceModel)) {
            return null;
        }

        return self::rootSpanId((string) $sourceModel, (string) $sourceId);
    }
```

- [ ] **Step 4: Run the full SpanBuilder test suite to verify the new test passes and nothing regressed**

Run: `vendor/bin/pest tests/Feature/Tracing/SpanBuilderTest.php`
Expected: PASS (all tests, including the new one)

- [ ] **Step 5: Commit**

```bash
git add src/Tracing/SpanBuilder.php tests/Feature/Tracing/SpanBuilderTest.php
git commit -m "feat: extract SpanBuilder::rootSpanId as a reusable static computation"
```

---

### Task 2: Extract shared Braintrust HTTP/project-id/error-handling logic into a trait

**Files:**
- Create: `src/Braintrust/InteractsWithBraintrustApi.php`
- Modify: `src/Eval/Scaffolding/BraintrustApi.php` (remove `request()`, `projectId()`, `client()` private methods; use the trait)
- Test: existing `tests/Feature/Eval/Js/BraintrustApiPublishTest.php`, `tests/Feature/Eval/Scaffolding/BraintrustDatasetSourceTest.php`, `tests/Feature/Eval/Scaffolding/BraintrustLogsSourceTest.php` (no new tests — this is a pure refactor verified by the existing suite)

**Interfaces:**
- Produces: `AgentSoftware\LaravelAiCompanion\Braintrust\InteractsWithBraintrustApi` trait with protected methods `client(): PendingRequest`, `projectId(): string`, and `request(callable $send): Response` (`$send` is `callable(): Response`). Later consumed by `BraintrustFeedbackClient` (Task 4).

- [ ] **Step 1: Run the existing Braintrust-related tests first to capture the current passing baseline**

Run: `vendor/bin/pest tests/Feature/Eval/Js/BraintrustApiPublishTest.php tests/Feature/Eval/Scaffolding/BraintrustDatasetSourceTest.php tests/Feature/Eval/Scaffolding/BraintrustLogsSourceTest.php`
Expected: PASS (all tests) — this is the safety net for the refactor in the next step.

- [ ] **Step 2: Create the trait**

Create `src/Braintrust/InteractsWithBraintrustApi.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Braintrust;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared HTTP client, project-id resolution, and error handling for classes
 * that talk to the Braintrust REST API directly (not through the neutral
 * TraceExporter pipeline) — currently BraintrustApi (eval scaffolding) and
 * BraintrustFeedbackClient (user feedback).
 */
trait InteractsWithBraintrustApi
{
    /** @param  callable(): Response  $send */
    protected function request(callable $send): Response
    {
        try {
            return $send()->throw();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 421) {
                throw new RuntimeException(
                    'Braintrust returned 421 DataPlaneRedirectError — your org is pinned to another data plane. '
                    .'Set BRAINTRUST_API_URL=https://api-eu.braintrust.dev and retry.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    protected function projectId(): string
    {
        $project = config('ai-companion.braintrust.project') ?? config('app.name');

        return Cache::rememberForever(
            "ai-companion:braintrust:project-id:{$project}",
            fn (): string => (string) $this->request(fn (): Response => $this->client()
                ->post('/v1/project', ['name' => $project]))
                ->json('id'),
        );
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('ai-companion.braintrust.api_url'))
            ->withToken((string) config('ai-companion.braintrust.api_key'))
            ->connectTimeout(5)
            ->timeout(30);
    }
}
```

- [ ] **Step 3: Adopt the trait in `BraintrustApi`**

In `src/Eval/Scaffolding/BraintrustApi.php`:

1. Add the import: `use AgentSoftware\LaravelAiCompanion\Braintrust\InteractsWithBraintrustApi;`
2. Add `use InteractsWithBraintrustApi;` as the first line inside the class body (right after `class BraintrustApi`).
3. Delete the three private methods at the bottom of the class (lines 197-233 in the original): `request()`, `projectId()`, `client()`.
4. Remove now-unused imports if any (`PendingRequest`, `Cache`, `RuntimeException` are no longer referenced directly — `RequestException` is still used in the `catch` inside the old `request()` which is now deleted, so remove that import too; `Http` import is also no longer needed). The class should end up importing only `Illuminate\Http\Client\Response` (still used in return type hints of the remaining public methods' closures).

The resulting class should still have all its public methods (`datasets()`, `datasetEvents()`, `logEvents()`, `toRow()`, `upsertFunction()`, `invokeFunction()`, `upsertOnlineRule()`) unchanged, calling `$this->client()`, `$this->projectId()`, `$this->request()` exactly as before — those calls now resolve to the trait's methods instead of local private ones.

- [ ] **Step 4: Run the same tests again to confirm the refactor didn't change behavior**

Run: `vendor/bin/pest tests/Feature/Eval/Js/BraintrustApiPublishTest.php tests/Feature/Eval/Scaffolding/BraintrustDatasetSourceTest.php tests/Feature/Eval/Scaffolding/BraintrustLogsSourceTest.php`
Expected: PASS (all tests, unchanged from Step 1's baseline)

- [ ] **Step 5: Commit**

```bash
git add src/Braintrust/InteractsWithBraintrustApi.php src/Eval/Scaffolding/BraintrustApi.php
git commit -m "refactor: extract shared Braintrust HTTP/project-id logic into a trait"
```

---

### Task 3: Add `BraintrustFeedbackException`

**Files:**
- Create: `src/Exceptions/BraintrustFeedbackException.php`

**Interfaces:**
- Produces: `AgentSoftware\LaravelAiCompanion\Exceptions\BraintrustFeedbackException extends RuntimeException` — thrown by `BraintrustFeedbackClient::record()` (Task 4) on misconfiguration or HTTP failure.

- [ ] **Step 1: Create the exception class**

Create `src/Exceptions/BraintrustFeedbackException.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Exceptions;

use RuntimeException;

class BraintrustFeedbackException extends RuntimeException
{
}
```

There's no test for a plain exception class with no behavior — it's exercised via `BraintrustFeedbackClient`'s tests in Task 4.

- [ ] **Step 2: Commit**

```bash
git add src/Exceptions/BraintrustFeedbackException.php
git commit -m "feat: add BraintrustFeedbackException"
```

---

### Task 4: Add `BraintrustFeedbackClient`

**Files:**
- Create: `src/Feedback/BraintrustFeedbackClient.php`
- Test: Create `tests/Feature/Feedback/BraintrustFeedbackClientTest.php`

**Interfaces:**
- Consumes: `SpanBuilder::rootSpanId(string $sourceModel, string $sourceId): string` (Task 1); `InteractsWithBraintrustApi` trait's `client()`, `projectId()`, `request()` (Task 2); `BraintrustFeedbackException` (Task 3).
- Produces: `BraintrustFeedbackClient::record(string $sourceModel, string $sourceId, bool $good, ?string $comment = null): void` — consumed by the `AiFeedback` facade (Task 5).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Feedback/BraintrustFeedbackClientTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Exceptions\BraintrustFeedbackException;
use AgentSoftware\LaravelAiCompanion\Feedback\BraintrustFeedbackClient;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai-companion.braintrust.enabled', true);
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'My Project');
});

function fakeBraintrustFeedbackApi(): void
{
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/feedback' => Http::response(['status' => 'success']),
    ]);
}

it('posts feedback for the deterministic root span id of the given source', function () {
    fakeBraintrustFeedbackApi();

    $expectedId = SpanBuilder::rootSpanId('App\Models\OnboardingSession', 'session-9');

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: true, comment: 'Great result');

    Http::assertSent(function (Request $request) use ($expectedId): bool {
        if (! str_contains($request->url(), '/v1/project_logs/proj-123/feedback')) {
            return false;
        }

        $feedback = $request->data()['feedback'][0];

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && $feedback['id'] === $expectedId
            && $feedback['scores'] === ['user_feedback' => 1.0]
            && $feedback['comment'] === 'Great result'
            && $feedback['source'] === 'app';
    });
});

it('maps a bad rating to a zero score and omits a null comment', function () {
    fakeBraintrustFeedbackApi();

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: false);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/feedback')) {
            return false;
        }

        $feedback = $request->data()['feedback'][0];

        return $feedback['scores'] === ['user_feedback' => 0.0]
            && ! array_key_exists('comment', $feedback);
    });
});

it('resolves and caches the project id across repeated calls', function () {
    fakeBraintrustFeedbackApi();

    $client = app(BraintrustFeedbackClient::class);
    $client->record('App\Models\OnboardingSession', 'session-9', good: true);
    $client->record('App\Models\OnboardingSession', 'session-9', good: true);

    Http::assertSentCount(3); // 1 project resolution + 2 feedback posts
});

it('throws when braintrust is disabled', function () {
    config()->set('ai-companion.braintrust.enabled', false);

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: true);
})->throws(BraintrustFeedbackException::class);

it('throws when no api key is configured', function () {
    config()->set('ai-companion.braintrust.api_key', null);

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: true);
})->throws(BraintrustFeedbackException::class);

it('throws when the http request fails', function () {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/feedback' => Http::response(status: 500),
    ]);

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: true);
})->throws(BraintrustFeedbackException::class);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Feedback/BraintrustFeedbackClientTest.php`
Expected: FAIL — class `AgentSoftware\LaravelAiCompanion\Feedback\BraintrustFeedbackClient` not found.

- [ ] **Step 3: Implement `BraintrustFeedbackClient`**

Create `src/Feedback/BraintrustFeedbackClient.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Feedback;

use AgentSoftware\LaravelAiCompanion\Braintrust\InteractsWithBraintrustApi;
use AgentSoftware\LaravelAiCompanion\Exceptions\BraintrustFeedbackException;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use RuntimeException;

/**
 * Records a user's thumbs up/down against the root span already shipped to
 * Braintrust for a business flow, keyed by the same $sourceModel/$sourceId
 * pair the app sets via Context for tracing (see SpanBuilder::rootSpanId).
 * A synchronous, foreground action — never queued, never silently swallowed.
 */
class BraintrustFeedbackClient
{
    use InteractsWithBraintrustApi;

    public function record(string $sourceModel, string $sourceId, bool $good, ?string $comment = null): void
    {
        if (! (bool) config('ai-companion.braintrust.enabled') || blank(config('ai-companion.braintrust.api_key'))) {
            throw new BraintrustFeedbackException(
                'Cannot record Braintrust feedback: tracing is not enabled or no API key is configured.',
            );
        }

        $feedback = array_filter([
            'id' => SpanBuilder::rootSpanId($sourceModel, $sourceId),
            'scores' => ['user_feedback' => $good ? 1.0 : 0.0],
            'comment' => $comment,
            'source' => 'app',
        ], fn (mixed $value): bool => $value !== null);

        try {
            $this->request(fn () => $this->client()
                ->post("/v1/project_logs/{$this->projectId()}/feedback", ['feedback' => [$feedback]]));
        } catch (RuntimeException $exception) {
            throw new BraintrustFeedbackException(
                "Braintrust feedback request failed: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Feedback/BraintrustFeedbackClientTest.php`
Expected: PASS (all 6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Feedback/BraintrustFeedbackClient.php tests/Feature/Feedback/BraintrustFeedbackClientTest.php
git commit -m "feat: add BraintrustFeedbackClient to record thumbs up/down feedback"
```

---

### Task 5: Add the `AiFeedback` facade

**Files:**
- Create: `src/Facades/AiFeedback.php`
- Test: Create `tests/Feature/Facades/AiFeedbackTest.php`

**Interfaces:**
- Consumes: `BraintrustFeedbackClient::record()` (Task 4).
- Produces: `AiFeedback::record(string $sourceModel, string $sourceId, bool $good, ?string $comment = null): void` — the public API surface consuming apps call.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Facades/AiFeedbackTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Facades\AiFeedback;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai-companion.braintrust.enabled', true);
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'My Project');
});

it('records feedback through the facade', function () {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/feedback' => Http::response(['status' => 'success']),
    ]);

    AiFeedback::record('App\Models\OnboardingSession', 'session-9', good: true, comment: 'Nice');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/feedback')
        && $request->data()['feedback'][0]['comment'] === 'Nice');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Facades/AiFeedbackTest.php`
Expected: FAIL — class `AgentSoftware\LaravelAiCompanion\Facades\AiFeedback` not found.

- [ ] **Step 3: Implement the facade**

Create `src/Facades/AiFeedback.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Facades;

use AgentSoftware\LaravelAiCompanion\Feedback\BraintrustFeedbackClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(string $sourceModel, string $sourceId, bool $good, ?string $comment = null)
 *
 * @see BraintrustFeedbackClient
 */
class AiFeedback extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BraintrustFeedbackClient::class;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Facades/AiFeedbackTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Facades/AiFeedback.php tests/Feature/Facades/AiFeedbackTest.php
git commit -m "feat: add AiFeedback facade"
```

---

### Task 6: Document the feature and run full verification

**Files:**
- Modify: `CLAUDE.md` (Project Overview numbered list — add the feedback feature)

**Interfaces:** None (documentation + verification only).

- [ ] **Step 1: Add the feature to `CLAUDE.md`'s Project Overview list**

In `CLAUDE.md`, after item 5 (`RecordAiToolCall` / tool call logging), add:

```markdown
6. **User feedback** — `AiFeedback::record($sourceModel, $sourceId, good: true|false, comment: ?string)` posts a thumbs up/down score to Braintrust against the deterministic root span already shipped for that business flow (see `Tracing/SpanBuilder::rootSpanId()`). Synchronous; throws `BraintrustFeedbackException` if Braintrust isn't enabled/configured or the request fails — no silent no-op, since this is a discrete foreground user action, not part of the tracing pipeline.
```

- [ ] **Step 2: Run the full test suite**

Run: `vendor/bin/pest`
Expected: PASS (all tests, no regressions)

- [ ] **Step 3: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: No errors

- [ ] **Step 4: Format**

Run: `vendor/bin/pint --dirty`
Expected: No diffs left uncommitted (pint applies fixes in place if any)

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document the Braintrust user feedback feature in CLAUDE.md"
```

# `ai:eval:scaffold` Interactive Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An interactive `php artisan ai:eval:scaffold` wizard that pulls historical AI traffic (Braintrust dataset, Braintrust logs, or `ai_response_logs`) into a dataset JSON file and scaffolds a reflection-mapped `EvalTarget` plus scorer stubs in the consuming app.

**Architecture:** The command is orchestration-only, built on Laravel Prompts. All logic lives in `src/Eval/Scaffolding/`: `AgentDiscovery` (find Agent classes in the host app), a `DatasetSource` contract with three implementations (two Braintrust ones delegating to a shared `BraintrustApi` HTTP helper, one Eloquent one), and two stub-based generators (`TargetGenerator`, `ScorerGenerator`).

**Tech Stack:** PHP 8.4, Laravel Prompts, `Illuminate\Support\Facades\Http`, Pest 4 + Orchestra Testbench, stubs via plain token replacement.

**Spec:** `docs/superpowers/specs/2026-07-02-eval-scaffold-command-design.md`

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- Namespace root: `AgentSoftware\LaravelAiCompanion`. Tests namespace: `AgentSoftware\LaravelAiCompanion\Tests`.
- Only `BraintrustApi` (and existing exporters) may know about Braintrust; the command, generators, discovery, and the `ResponseLogSource` stay operator-agnostic.
- Braintrust insert/fetch facts (from CLAUDE.md): base URL from `config('ai-companion.braintrust.api_url')`, token from `...api_key`, project find-or-create via `POST /v1/project {name}` (cache id forever), EU orgs get `421` from the default host — surface the `BRAINTRUST_API_URL=https://api-eu.braintrust.dev` hint.
- Dataset row shape (approved in spec): `{"prompt": <input>, "expected"?: <output>, ...flattened scalar metadata}`.
- Tests never hit a real API: `Http::fake` everywhere; sqlite fixtures for the DB source.
- Pest helper functions are global — name new helpers uniquely.
- Run `vendor/bin/pint --dirty` before every commit.
- Full suite check per task: `vendor/bin/pest` must pass before committing.

---

### Task 1: `AgentDiscovery`

**Files:**
- Create: `src/Eval/Scaffolding/AgentDiscovery.php`
- Test: `tests/Feature/Eval/Scaffolding/AgentDiscoveryTest.php`
- Create (fixture): `tests/Support/Eval/Scaffolding/FixtureAgent.php`

**Interfaces:**
- Produces: `AgentDiscovery::__construct(string $path, string $namespace)` and `discover(): array` returning a sorted `array<int, class-string>` of concrete classes implementing `Laravel\Ai\Contracts\Agent` found under `$path`.
- Consumed by: Task 7 (`ScaffoldEvalCommand` calls `new AgentDiscovery(app_path(), app()->getNamespace())`).

- [ ] **Step 1: Write the fixture agent**

`tests/Support/Eval/Scaffolding/FixtureAgent.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding;

use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;

final class FixtureAgent extends StubAgent
{
    public function __construct(
        public string $companyBrandTone,
        public int $maxPages = 5,
    ) {}
}
```

(`StubAgent` already implements `Laravel\Ai\Contracts\Agent`; if it declares a constructor, forward with `parent::__construct(...)` — check the file first.)

- [ ] **Step 2: Write the failing test**

`tests/Feature/Eval/Scaffolding/AgentDiscoveryTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\AgentDiscovery;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\FixtureAgent;

it('discovers concrete agent classes under a PSR-4 root', function (): void {
    $discovery = new AgentDiscovery(
        path: dirname(__DIR__, 3).'/Support',
        namespace: 'AgentSoftware\\LaravelAiCompanion\\Tests\\Support\\',
    );

    $found = $discovery->discover();

    expect($found)->toContain(FixtureAgent::class);
});

it('returns an empty array for a path with no agents', function (): void {
    $discovery = new AgentDiscovery(
        path: sys_get_temp_dir().'/definitely-empty-'.uniqid(),
        namespace: 'App\\',
    );

    expect($discovery->discover())->toBe([]);
});
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/AgentDiscoveryTest.php`
Expected: FAIL — class `AgentDiscovery` not found.

- [ ] **Step 4: Implement**

`src/Eval/Scaffolding/AgentDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use ReflectionClass;
use Throwable;

/**
 * Finds concrete Agent implementations under a PSR-4 root in the consuming
 * app (defaults are app_path()/app namespace at the call site).
 */
final readonly class AgentDiscovery
{
    public function __construct(
        private string $path,
        private string $namespace,
    ) {}

    /** @return array<int, class-string> */
    public function discover(): array
    {
        if (! File::isDirectory($this->path)) {
            return [];
        }

        $agents = [];

        foreach (File::allFiles($this->path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->namespace.Str::of($file->getRelativePathname())
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\')
                ->toString();

            try {
                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                continue;
            }

            if ($reflection->implementsInterface(Agent::class) && $reflection->isInstantiable()) {
                $agents[] = $class;
            }
        }

        sort($agents);

        return $agents;
    }
}
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/AgentDiscoveryTest.php`
Expected: PASS (both tests).

- [ ] **Step 6: Format, full suite, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/pest
git add src/Eval/Scaffolding/AgentDiscovery.php tests/Feature/Eval/Scaffolding/AgentDiscoveryTest.php tests/Support/Eval/Scaffolding/FixtureAgent.php
git commit -m "feat: agent discovery for eval scaffolding"
```

---

### Task 2: `DatasetSource` contract + `ResponseLogSource`

**Files:**
- Create: `src/Eval/Scaffolding/DatasetSource.php`
- Create: `src/Eval/Scaffolding/ResponseLogSource.php`
- Test: `tests/Feature/Eval/Scaffolding/ResponseLogSourceTest.php`

**Interfaces:**
- Produces: `interface DatasetSource { public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array; }` returning `array<int, array<string, mixed>>` rows shaped `{"prompt": string, "expected"?: mixed, ...scalar metadata}`.
- Produces: `ResponseLogSource::__construct(?string $agentClass = null)` — optional filter on the `agent` column.
- Consumed by: Tasks 3, 4 (same contract), Task 7 (command).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Eval/Scaffolding/ResponseLogSourceTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ResponseLogSource;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;

it('maps response logs to dataset rows with expected and metadata', function (): void {
    AiResponseLog::create([
        'agent' => 'App\\Ai\\Agents\\PagePlannerAgent',
        'prompt' => 'Plan pages for acme.com',
        'response' => ['text' => 'Here is the plan'],
        'properties' => ['company_brand_tone' => 'friendly', 'nested' => ['drop' => 'me']],
        'metadata' => ['tag' => 'onboarding'],
        'status' => 'success',
    ]);

    $rows = new ResponseLogSource('App\\Ai\\Agents\\PagePlannerAgent')
        ->fetch(limit: 10, includeExpected: true, includeMetadata: true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['prompt'])->toBe('Plan pages for acme.com')
        ->and($rows[0]['expected'])->toBe(['text' => 'Here is the plan'])
        ->and($rows[0]['company_brand_tone'])->toBe('friendly')
        ->and($rows[0]['tag'])->toBe('onboarding')
        ->and($rows[0])->not->toHaveKey('nested');
});

it('filters by agent class and honours the limit and checkboxes', function (): void {
    AiResponseLog::create(['agent' => 'A', 'prompt' => 'one', 'response' => ['text' => 'x'], 'status' => 'success']);
    AiResponseLog::create(['agent' => 'B', 'prompt' => 'two', 'response' => ['text' => 'y'], 'status' => 'success']);

    $rows = new ResponseLogSource('A')->fetch(limit: 10, includeExpected: false, includeMetadata: false);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBe(['prompt' => 'one']);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/ResponseLogSourceTest.php`
Expected: FAIL — `ResponseLogSource` not found.

- [ ] **Step 3: Implement contract and source**

`src/Eval/Scaffolding/DatasetSource.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

interface DatasetSource
{
    /**
     * Fetch historical interactions as eval dataset rows. Each row is
     * `{"prompt": string, "expected"?: mixed, ...flattened scalar metadata}`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array;
}
```

`src/Eval/Scaffolding/ResponseLogSource.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;

final readonly class ResponseLogSource implements DatasetSource
{
    public function __construct(private ?string $agentClass = null) {}

    public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array
    {
        return AiResponseLog::query()
            ->when($this->agentClass !== null, fn ($query) => $query->where('agent', $this->agentClass))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (AiResponseLog $log) use ($includeExpected, $includeMetadata): array {
                $row = ['prompt' => $log->prompt];

                if ($includeExpected && $log->response !== null) {
                    $row['expected'] = $log->response;
                }

                if ($includeMetadata) {
                    $row += array_filter(
                        [...($log->metadata ?? []), ...($log->properties ?? [])],
                        fn (mixed $value): bool => is_scalar($value),
                    );
                }

                return $row;
            })
            ->values()
            ->all();
    }
}
```

Note the merge order: `properties` wins over `metadata` on key collision, and `prompt`/`expected` can never be clobbered because `$row +=` keeps existing keys.

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/ResponseLogSourceTest.php`
Expected: PASS.

- [ ] **Step 5: Format, full suite, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/pest
git add src/Eval/Scaffolding/DatasetSource.php src/Eval/Scaffolding/ResponseLogSource.php tests/Feature/Eval/Scaffolding/ResponseLogSourceTest.php
git commit -m "feat: DatasetSource contract and ai_response_logs source"
```

---

### Task 3: `BraintrustApi` + `BraintrustDatasetSource`

**Files:**
- Create: `src/Eval/Scaffolding/BraintrustApi.php`
- Create: `src/Eval/Scaffolding/BraintrustDatasetSource.php`
- Test: `tests/Feature/Eval/Scaffolding/BraintrustDatasetSourceTest.php`

**Interfaces:**
- Produces: `BraintrustApi` with:
  - `datasets(): array` — `array<int, array{id: string, name: string}>` for the configured project
  - `datasetEvents(string $datasetId, int $limit): array` — raw event arrays
  - `logEvents(int $limit): array` — raw project-log event arrays (used in Task 4)
  - throws `RuntimeException` with the EU data-plane hint on HTTP 421.
- Produces: `BraintrustDatasetSource::__construct(BraintrustApi $api, string $datasetId)` implementing `DatasetSource` (Task 2).
- Consumed by: Task 4 (`logEvents`), Task 7 (command lists datasets and builds sources).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Eval/Scaffolding/BraintrustDatasetSourceTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustDatasetSource;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');
});

it('maps braintrust dataset events to rows', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::response(['events' => [
            [
                'input' => 'Plan pages for acme.com',
                'expected' => ['text' => 'the plan'],
                'metadata' => ['company_brand_tone' => 'friendly', 'nested' => ['drop' => 'me']],
            ],
            ['input' => ['input' => 'wrapped prompt']],
        ]]),
    ]);

    $rows = new BraintrustDatasetSource(new BraintrustApi, 'ds-1')
        ->fetch(limit: 25, includeExpected: true, includeMetadata: true);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['prompt' => 'Plan pages for acme.com', 'expected' => ['text' => 'the plan'], 'company_brand_tone' => 'friendly'])
        ->and($rows[1])->toBe(['prompt' => 'wrapped prompt']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/dataset/ds-1/fetch')
        && $request['limit'] === 25);
});

it('lists datasets for the configured project', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [
            ['id' => 'ds-1', 'name' => 'page-planner', 'ignored' => true],
        ]]),
    ]);

    expect(new BraintrustApi()->datasets())->toBe([['id' => 'ds-1', 'name' => 'page-planner']]);
});

it('turns a 421 into an actionable EU data-plane error', function (): void {
    Http::fake(['api.braintrust.dev/*' => Http::response(['error' => 'DataPlaneRedirectError'], 421)]);

    expect(fn () => new BraintrustApi()->datasets())
        ->toThrow(RuntimeException::class, 'BRAINTRUST_API_URL=https://api-eu.braintrust.dev');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/BraintrustDatasetSourceTest.php`
Expected: FAIL — `BraintrustApi` not found.

- [ ] **Step 3: Implement**

`src/Eval/Scaffolding/BraintrustApi.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-side Braintrust client for scaffolding: list datasets, fetch dataset
 * events, fetch recent project-log events. The ONLY Braintrust-aware class in
 * Eval/Scaffolding — everything else speaks DatasetSource rows.
 */
class BraintrustApi
{
    /** @return array<int, array{id: string, name: string}> */
    public function datasets(): array
    {
        $objects = (array) $this->request(fn (): Response => $this->client()
            ->get('/v1/dataset', ['project_id' => $this->projectId(), 'limit' => 100]))
            ->json('objects', []);

        return array_values(array_map(fn (array $dataset): array => [
            'id' => (string) $dataset['id'],
            'name' => (string) $dataset['name'],
        ], $objects));
    }

    /** @return array<int, array<string, mixed>> */
    public function datasetEvents(string $datasetId, int $limit): array
    {
        return (array) $this->request(fn (): Response => $this->client()
            ->post("/v1/dataset/{$datasetId}/fetch", ['limit' => $limit]))
            ->json('events', []);
    }

    /** @return array<int, array<string, mixed>> */
    public function logEvents(int $limit): array
    {
        return (array) $this->request(fn (): Response => $this->client()
            ->post("/v1/project_logs/{$this->projectId()}/fetch", ['limit' => $limit]))
            ->json('events', []);
    }

    /**
     * Normalize a Braintrust event (dataset or log) into a dataset row.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function toRow(array $event, bool $includeExpected, bool $includeMetadata): array
    {
        $input = $event['input'] ?? '';
        // Log spans wrap the prompt as {"input": "..."}; datasets may hold it raw.
        $prompt = is_array($input) ? ($input['input'] ?? json_encode($input)) : $input;

        $row = ['prompt' => (string) $prompt];

        $expected = $event['expected'] ?? $event['output'] ?? null;

        if ($includeExpected && $expected !== null) {
            $row['expected'] = $expected;
        }

        if ($includeMetadata && is_array($event['metadata'] ?? null)) {
            $row += array_filter($event['metadata'], fn (mixed $value): bool => is_scalar($value));
        }

        return $row;
    }

    /** @param  callable(): Response  $send */
    private function request(callable $send): Response
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

    private function projectId(): string
    {
        $project = config('ai-companion.braintrust.project') ?? config('app.name');

        return Cache::rememberForever(
            "ai-companion:braintrust:project-id:{$project}",
            fn (): string => (string) $this->request(fn (): Response => $this->client()
                ->post('/v1/project', ['name' => $project]))
                ->json('id'),
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('ai-companion.braintrust.api_url'))
            ->withToken((string) config('ai-companion.braintrust.api_key'));
    }
}
```

`src/Eval/Scaffolding/BraintrustDatasetSource.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

final readonly class BraintrustDatasetSource implements DatasetSource
{
    public function __construct(
        private BraintrustApi $api,
        private string $datasetId,
    ) {}

    public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array
    {
        return array_map(
            fn (array $event): array => BraintrustApi::toRow($event, $includeExpected, $includeMetadata),
            $this->api->datasetEvents($this->datasetId, $limit),
        );
    }
}
```

Note: the project-id cache key is shared with `BraintrustExporter` intentionally — same find-or-create, same value.

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/BraintrustDatasetSourceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Format, full suite, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/pest
git add src/Eval/Scaffolding/BraintrustApi.php src/Eval/Scaffolding/BraintrustDatasetSource.php tests/Feature/Eval/Scaffolding/BraintrustDatasetSourceTest.php
git commit -m "feat: Braintrust read API + dataset source for eval scaffolding"
```

---

### Task 4: `BraintrustLogsSource`

**Files:**
- Create: `src/Eval/Scaffolding/BraintrustLogsSource.php`
- Test: `tests/Feature/Eval/Scaffolding/BraintrustLogsSourceTest.php`

**Interfaces:**
- Consumes: `BraintrustApi::logEvents(int $limit)` and `BraintrustApi::toRow(...)` from Task 3.
- Produces: `BraintrustLogsSource::__construct(BraintrustApi $api, ?string $agentName = null)` implementing `DatasetSource`. `$agentName` filters events whose `span_attributes.name` or `metadata.agent` matches.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Eval/Scaffolding/BraintrustLogsSourceTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/BraintrustLogsSourceTest.php`
Expected: FAIL — `BraintrustLogsSource` not found.

- [ ] **Step 3: Implement**

`src/Eval/Scaffolding/BraintrustLogsSource.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

final readonly class BraintrustLogsSource implements DatasetSource
{
    public function __construct(
        private BraintrustApi $api,
        private ?string $agentName = null,
    ) {}

    public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array
    {
        $events = array_filter(
            $this->api->logEvents($limit),
            fn (array $event): bool => $this->matchesAgent($event),
        );

        return array_values(array_map(
            fn (array $event): array => BraintrustApi::toRow($event, $includeExpected, $includeMetadata),
            $events,
        ));
    }

    /** @param  array<string, mixed>  $event */
    private function matchesAgent(array $event): bool
    {
        if ($this->agentName === null) {
            return true;
        }

        $name = $event['span_attributes']['name'] ?? $event['metadata']['agent'] ?? null;

        return is_string($name) && str_contains($name, $this->agentName);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/BraintrustLogsSourceTest.php`
Expected: PASS.

- [ ] **Step 5: Format, full suite, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/pest
git add src/Eval/Scaffolding/BraintrustLogsSource.php tests/Feature/Eval/Scaffolding/BraintrustLogsSourceTest.php
git commit -m "feat: Braintrust recent-logs dataset source"
```

---

### Task 5: `ScorerGenerator`

**Files:**
- Create: `src/Eval/Scaffolding/ScorerGenerator.php`
- Create: `stubs/eval-scorer.stub`
- Test: `tests/Feature/Eval/Scaffolding/ScorerGeneratorTest.php`

**Interfaces:**
- Produces: `ScorerGenerator::generate(string $namespace, string $class): string` returning the full PHP source of a TODO scorer stub. Path decisions stay in the command.
- Consumed by: Task 7.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Eval/Scaffolding/ScorerGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerGenerator;

it('renders a scorer stub with namespace and class substituted', function (): void {
    $code = new ScorerGenerator()->generate('App\\Ai\\Eval\\Scorers', 'NoHallucinatedUrlsScorer');

    expect($code)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Ai\\Eval\\Scorers;')
        ->toContain('final class NoHallucinatedUrlsScorer implements Scorer')
        ->toContain('public function score(EvalSubject $subject): Score')
        ->toContain('TODO');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/ScorerGeneratorTest.php`
Expected: FAIL — `ScorerGenerator` not found.

- [ ] **Step 3: Implement stub and generator**

`stubs/eval-scorer.stub`:

```php
<?php

declare(strict_types=1);

namespace {{ namespace }};

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

final class {{ class }} implements Scorer
{
    public function score(EvalSubject $subject): Score
    {
        // TODO: score $subject->output (the agent response) against your rule.
        // Return a Score between 0.0 and 1.0 with an optional rationale.
        return new Score(name: '{{ name }}', value: 1.0);
    }
}
```

(Before writing the stub, check `src/Eval/Score.php` for the actual constructor signature and match it exactly — adjust the `new Score(...)` line if its parameters differ.)

`src/Eval/Scaffolding/ScorerGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Support\Str;

final readonly class ScorerGenerator
{
    public function generate(string $namespace, string $class): string
    {
        $stub = (string) file_get_contents(dirname(__DIR__, 3).'/stubs/eval-scorer.stub');

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ name }}'],
            [$namespace, $class, Str::of($class)->beforeLast('Scorer')->snake()->toString()],
            $stub,
        );
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/ScorerGeneratorTest.php`
Expected: PASS.

- [ ] **Step 5: Format, full suite, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/pest
git add stubs/eval-scorer.stub src/Eval/Scaffolding/ScorerGenerator.php tests/Feature/Eval/Scaffolding/ScorerGeneratorTest.php
git commit -m "feat: scorer stub generator for eval scaffolding"
```

---

### Task 6: `TargetGenerator` (reflection mapping)

**Files:**
- Create: `src/Eval/Scaffolding/TargetGenerator.php`
- Create: `src/Eval/Scaffolding/ScorerEntry.php`
- Create: `stubs/eval-target.stub`
- Test: `tests/Feature/Eval/Scaffolding/TargetGeneratorTest.php`

**Interfaces:**
- Produces: `ScorerEntry` value object: `__construct(public string $code, public array $imports)` — `$code` is one `new XxxScorer(...)` expression, `$imports` is a list of FQCNs it needs.
- Produces: `TargetGenerator::generate(string $namespace, string $class, string $agentClass, string $key, string $label, string $datasetPath, array $scorers): string` where `$scorers` is `array<int, ScorerEntry>`. Constructor mapping rules:
  - `string|int|float|bool` params → `name: (type) ($row['snake_name'] ?? default)`; when no default, `''`/`0`/`0.0`/`false`.
  - class/interface typed params → `name: app(\FQCN::class), // TODO: verify this resolves for evals`.
  - untyped/other params → `name: $row['snake_name'] ?? null, // TODO: map from a dataset row key`.
- Consumes: `FixtureAgent` from Task 1 for the test.
- Consumed by: Task 7.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Eval/Scaffolding/TargetGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerEntry;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\TargetGenerator;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\LlmJudgeScorer;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\FixtureAgent;

it('renders an eval target with reflection-mapped constructor args', function (): void {
    $code = new TargetGenerator()->generate(
        namespace: 'App\\Ai\\Eval\\Targets',
        class: 'FixtureAgentEvalTarget',
        agentClass: FixtureAgent::class,
        key: 'fixture-agent',
        label: 'Fixture Agent',
        datasetPath: 'database/eval-datasets/fixture-agent.json',
        scorers: [new ScorerEntry(
            code: "new LlmJudgeScorer(name: 'quality', rubric: 'Is it good?')",
            imports: [LlmJudgeScorer::class],
        )],
    );

    expect($code)
        ->toContain('namespace App\\Ai\\Eval\\Targets;')
        ->toContain('final class FixtureAgentEvalTarget implements EvalTarget')
        ->toContain("return 'fixture-agent';")
        ->toContain("return 'Fixture Agent';")
        ->toContain("return 'database/eval-datasets/fixture-agent.json';")
        ->toContain("return (string) (\$row['prompt'] ?? '');")
        ->toContain('use '.LlmJudgeScorer::class.';')
        ->toContain("new LlmJudgeScorer(name: 'quality', rubric: 'Is it good?')")
        ->toContain('return new FixtureAgent(')
        ->toContain("companyBrandTone: (string) (\$row['company_brand_tone'] ?? '')")
        ->toContain("maxPages: (int) (\$row['max_pages'] ?? 3)");
});

it('emits container resolution with a TODO for object-typed params', function (): void {
    $agent = new class('x') extends FixtureAgent
    {
        public function __construct(string $tone, public ?DateTimeInterface $clock = null)
        {
            parent::__construct($tone);
        }
    };

    $code = new TargetGenerator()->generate(
        namespace: 'App\\Ai\\Eval\\Targets',
        class: 'AnonEvalTarget',
        agentClass: $agent::class,
        key: 'anon',
        label: 'Anon',
        datasetPath: 'database/eval-datasets/anon.json',
        scorers: [],
    );

    expect($code)->toContain('app(\\DateTimeInterface::class)')
        ->and($code)->toContain('TODO');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/TargetGeneratorTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement stub, entry, generator**

`stubs/eval-target.stub`:

```php
<?php

declare(strict_types=1);

namespace {{ namespace }};

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalEnvironment;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use Laravel\Ai\Contracts\Agent;
{{ imports }}

final class {{ class }} implements EvalTarget
{
    public function key(): string
    {
        return '{{ key }}';
    }

    public function label(): string
    {
        return '{{ label }}';
    }

    public function defaultDataset(): string
    {
        return '{{ dataset }}';
    }

    /** @param array<string, mixed> $row */
    public function promptInput(array $row): string
    {
        return (string) ($row['prompt'] ?? '');
    }

    /** @return array<int, Scorer> */
    public function scorers(): array
    {
        return [
{{ scorers }}
        ];
    }

    /** @param array<string, mixed> $row */
    public function agent(EvalEnvironment $environment, array $row = []): Agent
    {
        return new {{ agentShort }}(
{{ agentArgs }}
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function subjectInput(array $row): array
    {
        return [];
    }
}
```

`src/Eval/Scaffolding/ScorerEntry.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

final readonly class ScorerEntry
{
    /** @param array<int, class-string> $imports */
    public function __construct(
        public string $code,
        public array $imports = [],
    ) {}
}
```

`src/Eval/Scaffolding/TargetGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Renders an EvalTarget class for an agent, mapping the agent's constructor
 * parameters from dataset row keys via reflection.
 */
final readonly class TargetGenerator
{
    /** @param array<int, ScorerEntry> $scorers */
    public function generate(
        string $namespace,
        string $class,
        string $agentClass,
        string $key,
        string $label,
        string $datasetPath,
        array $scorers,
    ): string {
        $stub = (string) file_get_contents(dirname(__DIR__, 3).'/stubs/eval-target.stub');

        $imports = collect($scorers)
            ->flatMap(fn (ScorerEntry $entry): array => $entry->imports)
            ->push($agentClass)
            ->unique()
            ->sort()
            ->map(fn (string $fqcn): string => "use {$fqcn};")
            ->implode("\n");

        $scorerLines = collect($scorers)
            ->map(fn (ScorerEntry $entry): string => "            {$entry->code},")
            ->implode("\n");

        return str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ class }}', '{{ key }}', '{{ label }}', '{{ dataset }}', '{{ scorers }}', '{{ agentShort }}', '{{ agentArgs }}'],
            [$namespace, $imports, $class, $key, $label, $datasetPath, $scorerLines, class_basename($agentClass), $this->agentArgs($agentClass)],
            $stub,
        );
    }

    /** @param class-string $agentClass */
    private function agentArgs(string $agentClass): string
    {
        $constructor = new ReflectionClass($agentClass)->getConstructor();

        if ($constructor === null) {
            return '';
        }

        return collect($constructor->getParameters())
            ->map(fn (ReflectionParameter $parameter): string => '            '.$this->argFor($parameter).',')
            ->implode("\n");
    }

    private function argFor(ReflectionParameter $parameter): string
    {
        $name = $parameter->getName();
        $rowKey = Str::snake($name);
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return "{$name}: app(\\{$type->getName()}::class) /* TODO: verify this resolves for evals */";
        }

        if ($type instanceof ReflectionNamedType && in_array($type->getName(), ['string', 'int', 'float', 'bool'], true)) {
            $cast = $type->getName();
            $default = $parameter->isDefaultValueAvailable()
                ? var_export($parameter->getDefaultValue(), true)
                : match ($cast) {
                    'string' => "''",
                    'int' => '0',
                    'float' => '0.0',
                    'bool' => 'false',
                };

            return "{$name}: ({$cast}) (\$row['{$rowKey}'] ?? {$default})";
        }

        return "{$name}: \$row['{$rowKey}'] ?? null /* TODO: map from a dataset row key */";
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/TargetGeneratorTest.php`
Expected: PASS. (If the anonymous-class test trips on reflection of anonymous classes, replace it with a named fixture `ObjectParamFixtureAgent` in `tests/Support/Eval/Scaffolding/` with a `DateTimeInterface $clock` param — same assertions.)

- [ ] **Step 5: Format, full suite, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/pest
git add stubs/eval-target.stub src/Eval/Scaffolding/ScorerEntry.php src/Eval/Scaffolding/TargetGenerator.php tests/Feature/Eval/Scaffolding/TargetGeneratorTest.php
git commit -m "feat: reflection-based EvalTarget generator"
```

---

### Task 7: `ScaffoldEvalCommand` + registration

**Files:**
- Create: `src/Eval/Commands/ScaffoldEvalCommand.php`
- Modify: `src/LaravelAiCompanionServiceProvider.php` (register the command)
- Test: `tests/Feature/Eval/Scaffolding/ScaffoldEvalCommandTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–6 with the exact signatures listed in their Interfaces blocks.
- Produces: artisan command `ai:eval:scaffold`, registered via spatie package-tools `hasCommand`.

- [ ] **Step 1: Write the failing test**

Uses the `ai_response_logs` flow (no HTTP) and Laravel Prompts' test-mode fallbacks (`expectsQuestion`/`expectsChoice`/`expectsConfirmation`). `tests/Feature/Eval/Scaffolding/ScaffoldEvalCommandTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\FixtureAgent;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(base_path('database/eval-datasets'));
    File::deleteDirectory(app_path('Ai'));
});

it('scaffolds a dataset and eval target from response logs', function (): void {
    AiResponseLog::create([
        'agent' => FixtureAgent::class,
        'prompt' => 'Plan pages for acme.com',
        'response' => ['text' => 'the plan'],
        'properties' => ['company_brand_tone' => 'friendly'],
        'status' => 'success',
    ]);

    // Point discovery at the test Support dir where FixtureAgent lives.
    config()->set('ai-companion.eval.scaffold.agent_path', dirname(__DIR__, 3).'/Support');
    config()->set('ai-companion.eval.scaffold.agent_namespace', 'AgentSoftware\\LaravelAiCompanion\\Tests\\Support\\');

    $this->artisan('ai:eval:scaffold')
        ->expectsChoice('Which agent is this eval for?', FixtureAgent::class, [FixtureAgent::class])
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsChoice('Where should the dataset come from?', 'response_logs', [
            'braintrust_dataset' => 'Existing Braintrust dataset',
            'braintrust_logs' => 'Recent Braintrust logs',
            'response_logs' => 'ai_response_logs table',
            'skip' => 'Skip — dataset file already exists',
        ])
        ->expectsQuestion('How many rows?', '50')
        ->expectsChoice('Include in each row (prompt is always included)', ['expected', 'metadata'], [
            'expected' => 'Output (as "expected")',
            'metadata' => 'Metadata (flattened scalars)',
        ])
        ->expectsChoice('Built-in scorers', ['llm_judge'], [
            'match' => 'MatchScorer',
            'llm_judge' => 'LlmJudgeScorer',
            'range' => 'RangeScorer',
            'tool_routing' => 'ToolRoutingScorer',
        ])
        ->expectsQuestion('LLM judge name', 'quality')
        ->expectsQuestion('LLM judge rubric', 'Is the plan complete and on-brand?')
        ->expectsQuestion('Custom scorer class names (comma-separated, blank for none)', 'NoHallucinatedUrlsScorer')
        ->assertSuccessful();

    $dataset = base_path('database/eval-datasets/fixture-agent.json');
    expect(File::exists($dataset))->toBeTrue();

    $rows = File::json($dataset);
    expect($rows[0]['prompt'])->toBe('Plan pages for acme.com')
        ->and($rows[0]['expected'])->toBe(['text' => 'the plan'])
        ->and($rows[0]['company_brand_tone'])->toBe('friendly');

    $target = app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php');
    expect(File::exists($target))->toBeTrue()
        ->and(File::get($target))->toContain("companyBrandTone: (string) (\$row['company_brand_tone'] ?? '')")
        ->and(File::get($target))->toContain("new LlmJudgeScorer(name: 'quality', rubric: 'Is the plan complete and on-brand?')")
        ->and(File::get($target))->toContain('new NoHallucinatedUrlsScorer');

    expect(File::exists(app_path('Ai/Eval/Scorers/NoHallucinatedUrlsScorer.php')))->toBeTrue();
});

it('fails softly when no agents are found', function (): void {
    config()->set('ai-companion.eval.scaffold.agent_path', sys_get_temp_dir().'/empty-'.uniqid());
    config()->set('ai-companion.eval.scaffold.agent_namespace', 'App\\');

    $this->artisan('ai:eval:scaffold')->assertFailed();
});
```

Note: if `expectsChoice` with a multiselect array proves brittle against the installed Prompts version, drive those two prompts with `expectsQuestion(label, arrayValue)` instead — the assertion targets (files on disk) stay identical.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/ScaffoldEvalCommandTest.php`
Expected: FAIL — command not found.

- [ ] **Step 3: Implement the command**

`src/Eval/Commands/ScaffoldEvalCommand.php`:

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Commands;

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\AgentDiscovery;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustDatasetSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustLogsSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\DatasetSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ResponseLogSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerEntry;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerGenerator;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\TargetGenerator;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\LlmJudgeScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\MatchScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\RangeScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\ToolRoutingScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Interactive wizard: pick an agent, pull historical traffic into a dataset
 * JSON file, and scaffold an EvalTarget (+ scorer stubs) in the consuming app.
 */
class ScaffoldEvalCommand extends Command
{
    protected $signature = 'ai:eval:scaffold';

    protected $description = 'Interactively scaffold an eval: dataset JSON, EvalTarget, and scorers';

    public function handle(): int
    {
        $agents = new AgentDiscovery(
            path: (string) config('ai-companion.eval.scaffold.agent_path', app_path()),
            namespace: (string) config('ai-companion.eval.scaffold.agent_namespace', app()->getNamespace()),
        )->discover();

        if ($agents === []) {
            error('No classes implementing Laravel\Ai\Contracts\Agent found under '.config('ai-companion.eval.scaffold.agent_path', app_path()));

            return self::FAILURE;
        }

        $agentClass = select(label: 'Which agent is this eval for?', options: array_combine($agents, $agents), scroll: 10);

        $defaultKey = Str::of(class_basename($agentClass))->beforeLast('Agent')->kebab()->toString();
        $key = text(label: 'Eval key', default: $defaultKey, required: true);
        $label = text(label: 'Eval label', default: Str::headline($defaultKey), required: true);
        $datasetPath = "database/eval-datasets/{$key}.json";

        if (! $this->buildDataset($agentClass, $datasetPath)) {
            return self::FAILURE;
        }

        $scorers = $this->askScorers();

        return $this->writeTarget($agentClass, $key, $label, $datasetPath, $scorers) ? self::SUCCESS : self::FAILURE;
    }

    private function buildDataset(string $agentClass, string $datasetPath): bool
    {
        $source = select(label: 'Where should the dataset come from?', options: [
            'braintrust_dataset' => 'Existing Braintrust dataset',
            'braintrust_logs' => 'Recent Braintrust logs',
            'response_logs' => 'ai_response_logs table',
            'skip' => 'Skip — dataset file already exists',
        ]);

        if ($source === 'skip') {
            return true;
        }

        if (in_array($source, ['braintrust_dataset', 'braintrust_logs'], true) && blank(config('ai-companion.braintrust.api_key'))) {
            error('Braintrust is not configured. Set BRAINTRUST_API_KEY (and BRAINTRUST_API_URL for EU orgs).');

            return false;
        }

        try {
            $datasetSource = $this->makeSource($source, $agentClass);

            if ($datasetSource === null) {
                return false;
            }

            $limit = (int) text(label: 'How many rows?', default: '50', required: true);

            $fields = multiselect(
                label: 'Include in each row (prompt is always included)',
                options: ['expected' => 'Output (as "expected")', 'metadata' => 'Metadata (flattened scalars)'],
                default: ['expected', 'metadata'],
            );

            $rows = $datasetSource->fetch(
                limit: max(1, $limit),
                includeExpected: in_array('expected', $fields, true),
                includeMetadata: in_array('metadata', $fields, true),
            );
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return false;
        }

        if ($rows === []) {
            error('The source returned no rows — nothing to write.');

            return false;
        }

        $full = base_path($datasetPath);

        if (File::exists($full) && ! confirm("Overwrite existing {$datasetPath}?", default: false)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($full));
        File::put($full, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        info(sprintf('Wrote %d row(s) to %s', count($rows), $datasetPath));

        return true;
    }

    private function makeSource(string $source, string $agentClass): ?DatasetSource
    {
        return match ($source) {
            'response_logs' => new ResponseLogSource($agentClass),
            'braintrust_logs' => new BraintrustLogsSource(new BraintrustApi, class_basename($agentClass)),
            'braintrust_dataset' => $this->pickBraintrustDataset(),
            default => null,
        };
    }

    private function pickBraintrustDataset(): ?DatasetSource
    {
        $api = new BraintrustApi;
        $datasets = $api->datasets();

        if ($datasets === []) {
            error('No datasets found in the configured Braintrust project.');

            return null;
        }

        $id = select(
            label: 'Which Braintrust dataset?',
            options: collect($datasets)->mapWithKeys(fn (array $d): array => [$d['id'] => $d['name']])->all(),
        );

        return new BraintrustDatasetSource($api, (string) $id);
    }

    /** @return array<int, ScorerEntry> */
    private function askScorers(): array
    {
        $builtins = multiselect(label: 'Built-in scorers', options: [
            'match' => 'MatchScorer',
            'llm_judge' => 'LlmJudgeScorer',
            'range' => 'RangeScorer',
            'tool_routing' => 'ToolRoutingScorer',
        ]);

        $entries = [];

        foreach ($builtins as $builtin) {
            $entries[] = match ($builtin) {
                'llm_judge' => new ScorerEntry(
                    code: sprintf(
                        "new LlmJudgeScorer(name: %s, rubric: %s)",
                        var_export(text(label: 'LLM judge name', default: 'quality', required: true), true),
                        var_export(text(label: 'LLM judge rubric', required: true), true),
                    ),
                    imports: [LlmJudgeScorer::class],
                ),
                'match' => new ScorerEntry(
                    code: "new MatchScorer(name: 'match', field: 'text', expected: 'expected') /* TODO: set field + expected row key */",
                    imports: [MatchScorer::class],
                ),
                'range' => new ScorerEntry(
                    code: "new RangeScorer(name: 'length', field: 'text', min: 1, max: 500) /* TODO: tune bounds */",
                    imports: [RangeScorer::class],
                ),
                'tool_routing' => new ScorerEntry(code: 'new ToolRoutingScorer', imports: [ToolRoutingScorer::class]),
            };
        }

        $custom = text(label: 'Custom scorer class names (comma-separated, blank for none)', default: '');

        foreach (array_filter(array_map(trim(...), explode(',', $custom))) as $name) {
            $class = Str::studly($name);
            $namespace = trim(app()->getNamespace(), '\\').'\\Ai\\Eval\\Scorers';
            $path = app_path("Ai/Eval/Scorers/{$class}.php");

            if (! File::exists($path) || confirm("Overwrite existing {$class}?", default: false)) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, new ScorerGenerator()->generate($namespace, $class));
                info("Created app/Ai/Eval/Scorers/{$class}.php");
            }

            $entries[] = new ScorerEntry(code: "new {$class}", imports: ["{$namespace}\\{$class}"]);
        }

        return $entries;
    }

    /** @param array<int, ScorerEntry> $scorers */
    private function writeTarget(string $agentClass, string $key, string $label, string $datasetPath, array $scorers): bool
    {
        $class = class_basename($agentClass).'EvalTarget';
        $namespace = trim(app()->getNamespace(), '\\').'\\Ai\\Eval\\Targets';
        $path = app_path("Ai/Eval/Targets/{$class}.php");

        if (File::exists($path) && ! confirm("Overwrite existing {$class}?", default: false)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, new TargetGenerator()->generate($namespace, $class, $agentClass, $key, $label, $datasetPath, $scorers));

        outro(sprintf(
            "Created app/Ai/Eval/Targets/%s.php\nNext: add %s\\%s::class to ai-companion.eval.targets, then run your eval command (see readme #evaluations).",
            $class,
            $namespace,
            $class,
        ));

        return true;
    }
}
```

- [ ] **Step 4: Register the command**

In `src/LaravelAiCompanionServiceProvider.php`, `configurePackage()`:

```php
use AgentSoftware\LaravelAiCompanion\Eval\Commands\ScaffoldEvalCommand;

$package
    ->name('laravel-ai-companion')
    ->hasConfigFile('ai-companion')
    ->hasCommand(ScaffoldEvalCommand::class)
    ->discoversMigrations();
```

Also add the two scaffold config keys to `config/ai-companion.php` under `eval`:

```php
// Where the scaffold command looks for Agent implementations.
'scaffold' => [
    'agent_path' => null,      // defaults to app_path() at runtime
    'agent_namespace' => null, // defaults to the app namespace at runtime
],
```

And adjust the command's two `config(...)` defaults to treat `null` as "use the runtime default":

```php
$path = config('ai-companion.eval.scaffold.agent_path') ?? app_path();
$namespace = config('ai-companion.eval.scaffold.agent_namespace') ?? app()->getNamespace();
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/pest tests/Feature/Eval/Scaffolding/ScaffoldEvalCommandTest.php`
Expected: PASS (2 tests). Iterate on prompt-expectation mismatches by matching the exact labels in the command.

- [ ] **Step 6: Static analysis, format, full suite, commit**

```bash
vendor/bin/phpstan analyse && vendor/bin/pint --dirty && vendor/bin/pest
git add -A src config stubs tests
git commit -m "feat: ai:eval:scaffold interactive command"
```

---

### Task 8: README documentation

**Files:**
- Modify: `README.md` (the `#evaluations` section)

**Interfaces:** none — docs only.

- [ ] **Step 1: Document the command**

Add a subsection under the existing Evaluations section:

```markdown
### Scaffolding an eval

Run the interactive wizard to go from historical traffic to a runnable eval:

​```bash
php artisan ai:eval:scaffold
​```

It will:

1. Discover your `Agent` classes and let you pick one.
2. Pull rows from an existing Braintrust dataset, recent Braintrust logs, or the
   `ai_response_logs` table into `database/eval-datasets/<key>.json`
   (`{"prompt": ..., "expected": ..., ...metadata}`).
3. Let you pick built-in scorers (the LLM-judge rubric is asked for inline) and
   generate TODO stubs for custom ones in `app/Ai/Eval/Scorers/`.
4. Generate `app/Ai/Eval/Targets/<Agent>EvalTarget.php` with the agent's
   constructor parameters mapped from dataset row keys.

Finish by registering the target in `config/ai-companion.php` under
`eval.targets`. EU-pinned Braintrust orgs must set
`BRAINTRUST_API_URL=https://api-eu.braintrust.dev`.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: document ai:eval:scaffold wizard"
```

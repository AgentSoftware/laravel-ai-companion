# Laravel AI Companion

A companion package for the [Laravel AI SDK](https://laravel.com/docs/ai-sdk). Three capabilities in one install:

1. **Token usage tracking** — automatic, global. Every `AgentPrompted` event writes one row to `ai_token_usages`.
2. **Response logging** — opt-in, per agent. Attach the `LogAiResponse` middleware to capture prompt/response/metadata to `ai_response_logs`.
3. **Evaluations** — run an agent over a dataset, score each output, and push a Braintrust experiment (or scored NDJSON).

## Installation

```bash
composer require agentsoftware/laravel-ai-companion
php artisan vendor:publish --tag="ai-companion-config"
php artisan migrate
```

The package auto-registers itself.

## Token usage tracking

```php
use AgentSoftware\LaravelAiCompanion\Facades\AiUsage;

// Overall totals
AiUsage::total();
// ['input_tokens' => 12400, 'output_tokens' => 3200, 'cache_write_tokens' => 800, 'cache_read_tokens' => 400]

// Totals for a specific agent
AiUsage::forAgent(MyAgent::class)->total();

// Totals grouped by agent class
AiUsage::byAgent();

// Totals scoped to a source (see "Source attribution" below)
AiUsage::forSource('session-abc')->total();
```

### Source attribution

To group token usage by a domain object (e.g. an onboarding session), set the source on the Laravel `Context` before prompting:

```php
use Illuminate\Support\Facades\Context;

Context::add('ai_usage_source_id', $session->id);
Context::add('ai_usage_source_model', $session::class);
```

The `source()` morph relation on `AiTokenUsage` lets you load the originating model directly.

## Response logging

Opt agents in by implementing `Laravel\Ai\Contracts\HasMiddleware` and adding `LogAiResponse`:

```php
use AgentSoftware\LaravelAiCompanion\Middleware\LogAiResponse;
use Laravel\Ai\Contracts\HasMiddleware;

class SegmentBuilderAgent implements Agent, HasMiddleware
{
    public function middleware(): array
    {
        return [new LogAiResponse];
    }
}
```

Each prompt writes a row to `ai_response_logs` with the prompt text, structured/text response, provider metadata, status (`running`/`success`/`failure`), and `duration_ms`.

To attach domain context (e.g. user/company) without relying on `Auth::user()` — which doesn't work in queued or CLI contexts — implement `HasLoggableProperties`:

```php
use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;

class SegmentBuilderAgent implements Agent, HasMiddleware, HasLoggableProperties
{
    public function loggableProperties(): array
    {
        return [
            'company_id' => $this->company->id,
            'user_id' => Auth::id(),
        ];
    }
}
```

The returned array is stored in the `properties` JSON column.

### Pruning

`AiResponseLog` uses `MassPrunable`. The service provider registers a daily `model:prune` schedule when pruning is enabled.

Configure via env:

```env
AI_COMPANION_PRUNE_ENABLED=true     # default true
AI_COMPANION_PRUNE_MONTHS=6         # default 6
AI_COMPANION_PRUNE_SCHEDULE="0 3 * * *"  # default 03:00 daily
```

## Braintrust tracing

Opt agents in to ship every interaction to [Braintrust](https://www.braintrust.dev) as a trace — tokens, latency, tool calls, failovers, and errors — without touching your AI call sites.

### Enabling

Set the following env vars and run your queue worker:

```dotenv
AI_COMPANION_BRAINTRUST_ENABLED=true
BRAINTRUST_API_KEY=sk-...
BRAINTRUST_PROJECT="My App"               # optional, defaults to app.name
AI_COMPANION_BRAINTRUST_QUEUE=tracing     # optional, keep export traffic off busy queues
AI_COMPANION_BRAINTRUST_QUEUE_CONNECTION= # optional, defaults to the default queue connection
```

### Trace grouping

Traces group by the same `Context` keys used for token tracking. Set them before prompting:

```php
use Illuminate\Support\Facades\Context;

Context::add('ai_usage_source_id', $session->id);
Context::add('ai_usage_source_model', $session::class);
```

With a source set, all agent calls for that source share one Braintrust trace tree. Without one, each invocation becomes its own trace.

### Delivery guarantees

Spans ship via a queued job (`ShipSpans`). The exporter never throws into AI calls — all export errors are caught and suppressed. Failed batches are attempted three times with backoff (10 s, 60 s), then dropped with a `Log::warning`.

### Hard-failure capture

By default, the `ExportTrace` event subscriber captures successful invocations. To also capture hard failures (exceptions that propagate out of the agent), attach the `TraceAiResponse` middleware to the agent:

```php
use AgentSoftware\LaravelAiCompanion\Middleware\TraceAiResponse;
use Laravel\Ai\Contracts\HasMiddleware;

class SegmentBuilderAgent implements Agent, HasMiddleware
{
    public function middleware(): array
    {
        return [TraceAiResponse::class];
    }
}
```

With provider failover configured, a recovered failover ships one error span per failed attempt plus the eventual success span — that is intended behaviour.

### Swapping the backend

All spans flow through the `TraceExporter` driver (`braintrust` by default). Register another from your app and select it via config — no package change:

```php
use AgentSoftware\LaravelAiCompanion\Tracing\Exporters\TraceExporterManager;

app(TraceExporterManager::class)->extend('my-backend', fn (): TraceExporter => new MyCustomExporter);
```

```dotenv
AI_COMPANION_TRACING_EXPORTER=my-backend
```

## Evaluations

Run an AI agent over a dataset, score each output, and push a [Braintrust](https://www.braintrust.dev) experiment — or write scored NDJSON when no Braintrust key is set. The package owns the run loop, scoring, reporting, and export; your app provides the agents, datasets, and a thin harness.

### Configure

Point the `eval` block in `config/ai-companion.php` at your app's classes:

```php
'eval' => [
    'exporter' => env('AI_COMPANION_EVAL_EXPORTER', 'braintrust'), // results driver
    'harness'  => App\Eval\MyHarness::class,                       // boots a row's world
    'targets'  => [App\Eval\SummaryTarget::class],                 // agents to evaluate
    'judge'    => ['provider' => null, 'model' => null],           // LLM-judge override; null = cheapest
    'output_path' => storage_path('app/braintrust'),              // NDJSON fallback dir
],
```

### The harness

The package never touches your models. Your harness boots a throwaway world for each dataset row and returns an `EvalEnvironment` (a marker your environment class implements). Each row runs inside a transaction that is rolled back, so an eval leaves no trace.

```php
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalEnvironment;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalHarness;

final readonly class MyEnvironment implements EvalEnvironment
{
    public function __construct(public User $user) {}
}

final class MyHarness implements EvalHarness
{
    public function boot(array $row): EvalEnvironment
    {
        return new MyEnvironment(User::factory()->create());
    }

    public function context(EvalEnvironment $environment): ?object
    {
        return null; // optional domain context for scorers to read
    }

    public function experimentMetadata(): array
    {
        return []; // experiment-level metadata, e.g. a config snapshot
    }
}
```

### Targets

A target names the agent under test, its dataset, and the scorers that define "good". Register each in the `eval.targets` config array.

```php
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalEnvironment;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use Laravel\Ai\Contracts\Agent;

final class SummaryTarget implements EvalTarget
{
    public function key(): string { return 'summary'; }                       // CLI arg + experiment prefix
    public function label(): string { return 'Summary agent'; }
    public function defaultDataset(): string { return 'tests/Fixtures/eval/summary.json'; }
    public function promptInput(array $row): string { return $row['input']; }  // text sent to the agent

    public function scorers(): array { return [/* see below */]; }

    public function agent(EvalEnvironment $environment, array $row = []): Agent
    {
        return SummaryAgent::make();
    }

    public function subjectInput(array $row): array                            // extra fields scorers need
    {
        return ['expected' => $row['expected'] ?? null];
    }
}
```

### Scorers

A scorer returns a `Score` in the range **0.0–1.0 where 1.0 = good** (the convention Braintrust and the result table assume — encapsulate any inverted polarity inside the scorer). Use the built-ins, or write your own.

```php
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\LlmJudgeScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\MatchScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\RangeScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\ToolRoutingScorer;

public function scorers(): array
{
    return [
        new RangeScorer(name: 'length', field: 'summary', mode: 'words', min: 10, max: 60),
        new MatchScorer(name: 'topic', field: 'topic', expected: 'expected', mode: 'contains'),
        new ToolRoutingScorer(declinePhrase: 'outside my capabilities'),
        new LlmJudgeScorer(name: 'faithful', rubric: '10 = no invented facts …', input: 'input', output: 'summary'),
    ];
}
```

A custom deterministic scorer implements the `Scorer` contract — read `$subject->output` / `$subject->input`, return a `Score`:

```php
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

final class HasCtaScorer implements Scorer
{
    public function score(EvalSubject $subject): Score
    {
        $hasCta = filled($subject->output['cta'] ?? null);

        return new Score('has_cta', $hasCta ? 1.0 : 0.0, ['cta' => $subject->output['cta'] ?? null]);
    }
}
```

`Score` metadata is free-form diagnostics; it is folded into the Braintrust event metadata (and shown on failures), so put the "why" there.

### Datasets

A dataset is a JSON array of rows. `promptInput()` / `subjectInput()` decide which keys are used; `tags` enable `--tag` filtering.

```json
[
  { "input": "Summarise the Q3 report", "expected": "revenue", "tags": ["finance"] }
]
```

### Running

Add a thin command that extends `RunEvalCommand` and declares the signature (the base resolves targets and the harness from config):

```php
use AgentSoftware\LaravelAiCompanion\Eval\Commands\RunEvalCommand;
use Illuminate\Console\Attributes\Signature;

#[Signature('app:eval {target?} {--dataset=} {--out=} {--provider=} {--model=} {--tag=} {--limit=} {--trials=1}')]
final class EvalCommand extends RunEvalCommand {}
```

```bash
php artisan app:eval summary            # interactive picker if target omitted
php artisan app:eval summary --limit=5  # smoke test the first 5 rows
php artisan app:eval summary --trials=3 # run each row 3x to measure variance
```

You get a coloured score table per run. With a Braintrust key set it pushes an experiment named `summary/v{prompt}/{model}` and attaches git metadata so Braintrust auto-selects the previous run on your branch as the baseline. Without a key, scored NDJSON is written to `eval.output_path`.

### Scaffolding an eval

Run the interactive wizard to go from historical traffic to a runnable eval:

```bash
php artisan ai:scaffold-eval
```

It will:

1. Discover your `Agent` classes and let you pick one.
2. Pull rows from an existing Braintrust dataset, recent Braintrust logs, or the
   `ai_response_logs` table into `database/eval-datasets/<key>.json`
   (`{"prompt": ..., "expected": ..., ...metadata}`).
3. Let you pick built-in scorers (the LLM-judge rubric is asked for inline)
   and name custom ones — every custom scorer scaffolds as a JS file in
   `resources/ai/scorers/` (any casing, normalised to a slug), runnable
   offline and publishable online (see below).
4. Generate `app/Ai/Eval/Targets/<Agent>EvalTarget.php` with the agent's
   constructor parameters mapped from dataset row keys.

Finish by registering the target in `config/ai-companion.php` under
`eval.targets`. EU-pinned Braintrust orgs must set
`BRAINTRUST_API_URL=https://api-eu.braintrust.dev`.

### JS scorers — write once, run offline, publish online

Self-contained checks (regex, URL validity, JSON shape) can be written as JS
scorer files instead of PHP classes. The file lives in your repo like any
other code — reviewed in PRs, versioned, single source of truth — and the
same file runs in two places: locally via Node during `ai:eval`, and (once
published) in Braintrust's sandbox against live traffic.

```bash
php artisan ai:scaffold-eval        # scaffolds resources/ai/scorers/<name>.js and wires it in
php artisan ai:eval page-planner    # runs it locally — iterate freely
php artisan ai:publish-eval         # interactively pick what goes live
```

A scorer file is a plain `async function handler({ output, input, expected })`
returning `{ score, metadata }` (score 0–1). Offline runs are **fully local**:
`JsScorer` executes the file via Node with zero Braintrust contact, so you can
iterate on the scoring logic as fast as you can re-run the eval. Requires
`node` on the machine running evals.

### Live evals — publishing for online scoring

Offline evals answer *"did my change make the agent better?"* before you
merge. **Live evals answer "is the agent still behaving in production?"** —
every real interaction gets scored as it happens, so quality drift, a bad
prompt deploy, or a new failure mode (an agent hallucinating image URLs, say)
shows up in Braintrust within minutes as a falling score you can chart,
filter, and alert on — instead of waiting for a customer to notice.

Publishing is an explicit, selective step — nothing reaches Braintrust until
you run it:

```bash
php artisan ai:publish-eval
```

The wizard walks you through: pick the eval target → tick which of its JS
scorers go live (unticked scorers never leave your repo — PHP scorers can't
be published since Braintrust can't run PHP) → set a sampling rate (every
scored span runs every published scorer; sample down when traffic is high).
For each ticked scorer it then:

1. Creates or updates the Braintrust scorer function (matched by slug, skipped
   when the code is unchanged — the repo stays the source of truth).
2. **Smoke-tests it in Braintrust's real sandbox** — the runtimes are close
   but not identical, and the publish aborts before touching any rule if the
   scorer fails up there.
3. Creates or updates the online scoring rule for the target's agent spans.

Re-publishing reconciles: the rule ends up with exactly the ticked set, so
un-ticking a scorer on the next publish removes it from live scoring. Flags
for CI: `--target=`, `--scorers=`, `--sample=`.

Note: the online rule matches live spans by exact name, derived from the
target key (`page-planner` → `PagePlanner` and `PagePlannerAgent`), so keep
the scaffold's default key or one derived from the agent class name — an
unrelated key publishes a rule that silently matches nothing.

### Swapping the exporter

Results flow through the `ExperimentExporter` driver (`braintrust` by default). Register another from your app — no package change — and select it via config:

```php
use AgentSoftware\LaravelAiCompanion\Eval\Exporters\ExperimentExporterManager;

app(ExperimentExporterManager::class)->extend('my-backend', fn (): ExperimentExporter => new MyExporter);
```

```dotenv
AI_COMPANION_EVAL_EXPORTER=my-backend
```

## How it works

- Token tracking listens to the `AgentPrompted` event dispatched by `laravel/ai`. One row per prompt, always.
- Response logging hooks into the agent middleware pipeline. The middleware writes a `running` row before calling the agent, updates it to `success`/`failure` after, and records `duration_ms`.

The two tables stay independent — token tracking works without response logging, and vice versa.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `laravel/ai`

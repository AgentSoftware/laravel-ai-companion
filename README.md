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

Enabled by default — every prompt writes a row to `ai_token_usages`. If your app already tracks token usage itself, turn it off in the published config (`'token_usage' => ['enabled' => false]`) or via env:

```env
AI_COMPANION_TOKEN_USAGE_ENABLED=false
```

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

`input_tokens` is the provider's full input count and *includes* the cache-read and cache-write tokens (stored separately as subsets); `output_tokens` includes reasoning tokens. Price each input category separately rather than applying one rate to `input_tokens`. Rows written by companion 4.x (`laravel/ai` 0.x) excluded cached tokens — see [UPGRADE.md](UPGRADE.md).

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

Each run writes one row to `ai_response_logs` with the invocation id, prompt text, structured/text response, provider metadata, status (`running`/`success`/`failure`), and `duration_ms` — however many generation steps or provider failovers the run takes. Streamed runs are not logged.

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

The `ExportTrace` subscriber captures failed runs automatically from the SDK's `AgentFailed` event: a run that throws after exhausting its providers ships one error span carrying the exception and any failovers along the way. A run that fails over and then recovers ships only its success span, with the failed attempts recorded in `metadata.failovers`.

The `TraceAiResponse` middleware is no longer needed and is a deprecated no-op.

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

#### Skipping dataset rows

A target that must ignore some rows (drafts with no `expected` answer yet, say) can implement `FiltersDatasetRows`. Targets that run every row simply omit it.

```php
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\FiltersDatasetRows;

final class SummaryTarget implements EvalTarget, FiltersDatasetRows
{
    // …

    public function includeRow(array $row): bool
    {
        return filled($row['expected'] ?? null);
    }
}
```

The runner applies `includeRow()` right after loading the dataset, so exclusion happens **before** `--tag` and `--limit` (`--limit=5` means five included rows, not five rows of which some were dropped). It prints `Skipped N rows (excluded by target).` when any rows were excluded.

### Classifier targets

To evaluate a classification (`Laravel\Ai\Classification`) instead of an agent, implement `ClassifierEvalTarget`. It has the same `key()`, `label()`, `defaultDataset()` and `scorers()`, plus `classification()`, which turns a row into the state, the keyed questions, any attachments, and the answers the row expects. The runner classifies each row with `--provider` / `--model`, so one dataset can be compared across TypeSafe, OpenAI and OpenRouter. Classifier targets need no harness; when one is configured, its `experimentMetadata()` is still recorded. One target can serve many decisions by switching on a row field:

```php
use AgentSoftware\LaravelAiCompanion\Eval\ClassificationCase;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ClassifierEvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\ExpectedAnswer;
use AgentSoftware\LaravelAiCompanion\Eval\ExpectedAnswerTag;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\BooleanAnswerScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\ChoiceAnswerScorer;

final class DecisionTarget implements ClassifierEvalTarget
{
    public function key(): string { return 'decisions'; }
    public function label(): string { return 'Decisions'; }
    public function defaultDataset(): string { return 'tests/Fixtures/eval/decisions.json'; }

    public function scorers(): array
    {
        return [
            new ChoiceAnswerScorer('priority'),
            new BooleanAnswerScorer('gas', threshold: 0.5),
            new BooleanAnswerScorer('gas', tag: ExpectedAnswerTag::MustCatch), // gas_must_catch: recall; a miss fails the run
            new BooleanAnswerScorer('gas', tag: ExpectedAnswerTag::MustPass),  // gas_must_pass: 1 - false-positive rate
        ];
    }

    public function classification(array $row): ClassificationCase
    {
        $decision = Decision::for($row['decision'], $row['inputs']); // your own mapping

        return new ClassificationCase(
            state: $decision->state(),
            questions: $decision->questions(),
            expected: ExpectedAnswer::fromDataset($row['expected'] ?? []),
            attachments: $row['photos'] ?? [], // Braintrust attachment references arrive as SDK files
        );
    }
}
```

A row's `expected` is keyed by question: a bare answer, or `{"answer": …, "tag": "must_catch" | "must_pass"}`. Only providers that support classification attachments (OpenAI) accept them; a row sent to one that doesn't is reported as a failed run.

#### Datasets in Braintrust

Rows that must stay out of git (photos, say) can live in a Braintrust dataset in the configured project: pass `--dataset=braintrust:<dataset name>` (or return it from `defaultDataset()`). Each row is the event's `input`, plus its `expected` and `tags` when the input carries none. Before the rows run, every `braintrust_attachment` reference in them, at any depth, is downloaded with the configured key and replaced with a `LocalImage` (or `LocalDocument`), so the target passes the field straight to `attachments`. Each attachment is downloaded once per run, and only for rows left after `--tag` / `--limit`, into a private temporary directory that is deleted when the run ends.

```json
[
  { "decision": "hazard", "inputs": {"report": "I can smell gas"}, "expected": {"gas": {"answer": true, "tag": "must_catch"}}, "tags": ["hazard"] },
  { "decision": "priority", "inputs": {"report": "The boiler is leaking"}, "expected": {"priority": "urgent"} }
]
```

The run prints each score's mean and a confusion matrix per question, and exports input (state and questions), output (each answer with its probabilities), expected and scores to Braintrust as `{key}/{provider}/{model}`. Any `blocking` score below 1.0 (a missed must-catch row) fails the command after the results are exported, and so does any classification row that fails to run, since its gates were never measured.

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

A row's `expected` can be an array or a plain string; both are exported to Braintrust experiments.

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
php artisan app:eval decisions --provider=openai  # a classifier target on another provider
php artisan app:eval decisions --dataset=braintrust:photo-reports --provider=openai
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
- Response logging listens to the `PromptingAgent`, `AgentPrompted`, and `AgentFailed` events for agents carrying the `LogAiResponse` middleware. It writes a `running` row when the run starts, updates it to `success`/`failure` when the run ends, and records `duration_ms`. (Agent middleware wraps each generation step, so the middleware itself is only the opt-in marker.)

The two tables stay independent — token tracking works without response logging, and vice versa.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `laravel/ai` 1.x (use companion 4.x for `laravel/ai` 0.9–0.11 — see [UPGRADE.md](UPGRADE.md))

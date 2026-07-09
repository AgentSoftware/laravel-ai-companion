# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project Overview

**laravel-ai-companion** is a companion package for the official Laravel AI SDK (`laravel/ai`). It provides:

1. **Token usage tracking** — global `AgentPrompted` listener → `ai_token_usages` table, grouped by `source_id`/`source_model` from Laravel `Context` (keys `ai_usage_source_id` / `ai_usage_source_model`).
2. **Response logging** — opt-in `LogAiResponse` agent middleware → `ai_response_logs` table.
3. **Braintrust tracing** — opt-in exporter shipping every agent interaction to Braintrust as trace trees (see below).
4. **Evaluations** — offline eval runs over datasets (`ai:eval`, app-extended), interactive scaffolding (`ai:scaffold-eval`), and publishing JS scorers for live/online scoring (`ai:publish-eval`) — see "Evaluations lifecycle" below.
5. **Tool call logging** — opt-in `RecordAiToolCall` event subscriber → `ai_tool_calls` table, one row per `ToolInvoked` event, hard-linked via `ai_response_log_id` to the `ai_response_logs` row for that invocation. Gated by `tool_call_logs.enabled` config; silently no-ops if no matching response log exists (e.g. `LogAiResponse` middleware not active for that agent).

Stack: PHP 8.4+, spatie/laravel-package-tools, Pest 4 + Orchestra Testbench.

## Commands

```bash
vendor/bin/pest                 # run tests
vendor/bin/pint --dirty         # format (run before committing)
vendor/bin/phpstan analyse      # static analysis
```

## Architecture: Braintrust tracing

```
laravel/ai events → Tracing/Listeners/ExportTrace → Tracing/SpanBuilder (neutral spans)
  → Tracing/Jobs/ShipSpans (queued) → Contracts/TraceExporter ← Exporters/BraintrustExporter
```

- Everything upstream of the `TraceExporter` contract is operator-agnostic. `BraintrustExporter` is the ONLY Braintrust-aware class. Swap operators by rebinding the contract.
- Trace grouping: deterministic UUIDv5 root span derived from the `Context` source keys. One business flow (e.g. an onboarding session spanning many queued jobs) = one trace tree, because `Context` propagates through queues.
- The exporter must NEVER throw into an AI call: listeners are `rescue()`-wrapped, shipping is queued with retry → log-and-drop, `TraceTimings` is size-capped (orphaned entries are expected — hard failures fire no `AgentPrompted` event).
- Design spec and plan: `docs/superpowers/specs/` and `docs/superpowers/plans/`.

## Working with Braintrust

When implementing or debugging anything Braintrust-related, consult the official docs index first: **https://www.braintrust.dev/docs/llms.txt** — fetch it and follow links to the relevant pages (tracing, online scoring, API reference) rather than answering from memory. The API surface changes; the openapi spec lives at https://github.com/braintrustdata/braintrust-openapi.

Hard-won API facts (verified 2026-06, cost us production failures — keep these true in `BraintrustExporter`):

- Insert endpoint: `POST /v1/project_logs/{project_id}/insert` with `{"events": [...]}`.
- Span linking uses `span_parents` (an ARRAY of parent span ids). There is NO `parent_span_id` field.
- `metrics` and `metadata` must be JSON **objects or absent** — an empty PHP array encodes to `[]` (JSON array) and the API rejects the whole batch with `400 invalid_type`. Never ship them empty.
- Metrics field names: `start`/`end` (unix epoch seconds, float), `prompt_tokens`, `completion_tokens`, `tokens`; extra numeric keys (cache/reasoning tokens) are allowed as custom metrics. Cost is derived from `metadata.model`.
- `POST /v1/project {name}` is find-or-create; the project id is cached forever per name.
- **Data planes**: some orgs (including ours, Street Group) are EU-pinned — inserts to the default `api.braintrust.dev` fail with `421 DataPlaneRedirectError`. Consuming apps must set `BRAINTRUST_API_URL=https://api-eu.braintrust.dev`. A future improvement could follow the 421's `RedirectUrl` automatically.
- The package provides generic eval contracts + offline experiment transport, but never defines what "good" means. `Eval/` owns the `Scorer`/`Score`/`EvalSubject` contracts and the `ExperimentExporter` (Braintrust experiment REST push). Concrete scorers, rubrics, and datasets live in the consuming app.
- Read-side + publish Braintrust calls for the eval tooling go through `Eval/Scaffolding/BraintrustApi` (BTQL via `POST /btql` — the plain fetch endpoint has NO filter; functions via `/v1/function` incl. `slug` list filter; online rules via `/v1/project_score` with `score_type: 'online'`, matched by `project_score_name`). `_is_merge: true` insert events update existing spans by id.

## Evaluations lifecycle

Three commands, one flow — scaffold → iterate offline → publish live:

- `ai:scaffold-eval` — interactive wizard: pick an agent (auto-discovered), pull a dataset from Braintrust logs/datasets or `ai_response_logs`, pick built-in scorers and name custom ones (always scaffolded as JS files in `resources/ai/scorers/`), generate the `EvalTarget` (constructor reflection-mapped from row keys).
- `ai:eval <key>` (app-extended `RunEvalCommand`) — offline: replays dataset rows through the real agent, scores, pushes a Braintrust experiment. **Offline stays offline for JS scorers**: `Eval/Js/JsScorer` runs the file locally via Node (`scorer-runner.mjs`, Braintrust handler convention, 60s process timeout) with zero HTTP.
- `ai:publish-eval` — THE publish boundary, and the only code path that writes scorers to Braintrust: interactively tick which JS scorers go live (PHP scorers can't be published — Braintrust can't run PHP), set sampling; per scorer upsert-function-by-slug (skip when code unchanged) → **smoke test in the real sandbox** (runtimes diverge — e.g. `AbortSignal.timeout` doesn't exist there) → upsert the online rule (reconciled by name `{key} (online)`).

Why live/online evals: offline runs gate changes before merge; online scoring catches production drift — every live agent span gets scored at ingest, so a bad prompt deploy or new failure mode shows as a falling score chartable/alertable in Braintrust. Offline score names come from the JS file slug (snake_cased); verify the online score key on first publish before relying on chart alignment across the two.

Gotcha: online rules match spans by EXACT name (`apply_to_span_names`); the publish command derives `[PagePlanner, PagePlannerAgent]` from the target key, so eval keys must stay derived from the agent class name (BTQL log pulls use ILIKE and are more forgiving).

Gotcha (verified 2026-07, broke production online scoring): Braintrust's online runtime only logs a scorer return that is a bare number or a full Score object — `name` (non-empty string) is REQUIRED alongside `score`; `{score, metadata}` fails at ingest with `Cannot log {...} as a score`. Offline never catches this because `JsScorer`/`scorer-runner.mjs` supply the name from the file slug and ignore any returned one. Scaffolded scorers must return `name` as the snake_cased slug (= `JsScorer::name()`) so offline and online score names align, and the publish smoke test must reject name-less object returns.

## Conventions

- Every PHP file: `declare(strict_types=1);`. Listeners/middleware are `readonly` classes.
- Neutral span shape (keys: `id`, `trace_id`, `parent_id`, `name`, `type`, `input`, `output`, `error`, `metadata`, `metrics`) must stay identical across `SpanBuilder`, `ShipSpans`, `TraceAiResponse`, and exporters.
- Pest helper functions are global across the suite — shared helpers live in `tests/Pest.php`; name new ones uniquely.
- Tests: bind a fake `TraceExporter` to assert span batches; `Http::fake` for exporter tests; never hit the real API.

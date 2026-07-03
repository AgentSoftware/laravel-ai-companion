# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project Overview

**laravel-ai-companion** is a companion package for the official Laravel AI SDK (`laravel/ai`). It provides:

1. **Token usage tracking** — global `AgentPrompted` listener → `ai_token_usages` table, grouped by `source_id`/`source_model` from Laravel `Context` (keys `ai_usage_source_id` / `ai_usage_source_model`).
2. **Response logging** — opt-in `LogAiResponse` agent middleware → `ai_response_logs` table.
3. **Braintrust tracing** — opt-in exporter shipping every agent interaction to Braintrust as trace trees (see below).

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
- The insert endpoint has a **20mb payload cap** — `BraintrustExporter` chunks events per request (`ai-companion.braintrust.max_payload_bytes`, default 10mb for headroom) and drops single events that exceed it with a log warning.
- **Data planes**: some orgs (including ours, Street Group) are EU-pinned — inserts to the default `api.braintrust.dev` fail with `421 DataPlaneRedirectError`. Consuming apps must set `BRAINTRUST_API_URL=https://api-eu.braintrust.dev`. A future improvement could follow the 421's `RedirectUrl` automatically.
- The package provides generic eval contracts + offline experiment transport, but never defines what "good" means. `Eval/` owns the `Scorer`/`Score`/`EvalSubject` contracts and the `ExperimentExporter` (Braintrust experiment REST push). Concrete scorers, rubrics, datasets, the LLM-judge, and any online/live-span scoring live in the consuming app and implement the `Scorer` contract.

## Conventions

- Every PHP file: `declare(strict_types=1);`. Listeners/middleware are `readonly` classes.
- Neutral span shape (keys: `id`, `trace_id`, `parent_id`, `name`, `type`, `input`, `output`, `error`, `metadata`, `metrics`) must stay identical across `SpanBuilder`, `ShipSpans`, `TraceAiResponse`, and exporters.
- Pest helper functions are global across the suite — shared helpers live in `tests/Pest.php`; name new ones uniquely.
- Tests: bind a fake `TraceExporter` to assert span batches; `Http::fake` for exporter tests; never hit the real API.

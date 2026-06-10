# Braintrust Exporter for laravel-ai-companion

**Date:** 2026-06-10
**Status:** Approved

## Goal

Ship every Laravel AI SDK agent interaction to [Braintrust](https://www.braintrust.dev) as traces, so token usage, costs, latency, and behaviour are observable across every app that installs this package. The exporter is opt-in, additive (existing `ai_token_usages` / `ai_response_logs` tracking is untouched), and requires zero changes to agents or application code.

## Architecture

```
laravel/ai events ──► Braintrust listeners ──► SpanBuilder ──► queued ShipSpansToBraintrust job ──► POST /v1/project_logs/{project_id}/insert
```

The exporter rides the same hooks the package already uses: `laravel/ai` lifecycle events and the `ai_usage_source_id` / `ai_usage_source_model` values that consuming apps place in Laravel `Context` (e.g. websites.spectre's `SetAiUsageSource` job middleware). Because `Context` propagates across queued jobs, a multi-job business process (such as the onboarding pipeline's ~20 chained jobs) lands in Braintrust as a single trace tree with no new plumbing.

## Configuration

New `braintrust` section in `config/ai-companion.php`:

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `false` | Master switch. When false (or no API key), no listeners do any work. |
| `api_key` | `env('BRAINTRUST_API_KEY')` | Braintrust API key. |
| `project` | `env('BRAINTRUST_PROJECT', config('app.name'))` | Braintrust project name. Resolved to a project ID via the Braintrust API on first use and cached forever (`Cache::rememberForever`). |
| `queue.connection` | `null` (default connection) | Connection for ship jobs. |
| `queue.queue` | `null` (default queue) | Queue name for ship jobs, so apps can keep export traffic off busy queues. |

## Trace model

Mirrors the package's existing grouping: `source_model` is the process, the agent is the breakdown.

- **Trace root** — the business source from `Context`. Root `span_id` = deterministic UUIDv5 of `"{source_model}:{source_id}"`; `root_span_id` = itself; span name = `class_basename(source_model)`. Braintrust merges inserted events that share an `id`, so every listener upserts the root unconditionally — the first creates it, the rest are no-ops.
- **Agent span** — one per invocation. `span_id` = the SDK `invocationId`; parent = root span. Fields:
  - `input`: prompt text (plus agent instructions where available)
  - `output`: response text, or the structured array for `StructuredAgentResponse`
  - `metadata`: agent class, model, provider, app name, environment, `source_model`, `source_id`
  - `metrics`: `prompt_tokens`, `completion_tokens`, `tokens` (total), cache write/read tokens, `start`/`end` (unix-epoch floats)
- **Tool span** — one per `ToolInvoked`. `span_id` = `toolInvocationId`; parent = the agent span (`invocationId`). `input` = arguments, `output` = result, name = tool class.
- **Fallback** — when no source is in `Context`, the agent span is itself the trace root (one trace per invocation).

> Exact `metrics` field names must be pinned against the [braintrust-openapi](https://github.com/braintrustdata/braintrust-openapi) spec during implementation so Braintrust auto-computes costs from `metadata.model`.

## Components

All under `src/Braintrust/`:

- **`TraceTimings`** — singleton holding `microtime(true)` start times keyed by `invocationId` / `toolInvocationId`. Written at `PromptingAgent` / `InvokingTool`, consumed at `AgentPrompted` / `ToolInvoked`. Event pairs always occur within one process, so in-memory storage is safe.
- **`Listeners\ExportToBraintrust`** — event subscriber for `PromptingAgent`, `AgentPrompted`, `InvokingTool`, `ToolInvoked`, `AgentFailedOver`. Builds plain-array span events and dispatches the ship job. Every handler body is wrapped in `rescue()` — the exporter must never break an AI call.
- **`SpanBuilder`** — pure functions mapping event + timing + context → Braintrust event arrays. No IO; unit-tested directly.
- **`Jobs\ShipSpansToBraintrust`** — queued job carrying only plain arrays (no SDK objects). Resolves and caches the project ID, posts the batch to `/v1/project_logs/{project_id}/insert`, retries with backoff, and logs-and-drops on final failure.
- **Service provider wiring** — listeners registered in `packageBooted()` only when `braintrust.enabled` is true and an API key is present.

## Error and failover capture

- `AgentFailedOver` → upsert onto the agent span: `error` field plus metadata recording the original and fallback providers.
- Hard failures (exception, no failover): the SDK fires no failure event, so the package ships a new `Middleware\TraceAiResponse` middleware (sibling to `LogAiResponse`, same opt-in pattern) that catches the exception, marks the Braintrust span as errored with the exception message and duration, and rethrows. Error capture is independent of whether an agent also uses `LogAiResponse`.

## Testing

Pest + Orchestra Testbench, consistent with existing package tests:

- Fire SDK events with fake data → assert ship jobs queued with correct span arrays (`Queue::fake`).
- Assert HTTP payload shape and auth header (`Http::fake`), including project-ID resolution and caching.
- Trace shape: with source context set → root + child spans share `root_span_id`; without → agent span is root.
- Resilience: Braintrust down / missing API key / `enabled=false` → no exceptions, no queued jobs (where applicable), agent calls unaffected.

## Out of scope (v1) — phase 2 candidates

- Images, embeddings, transcription, and streaming (`AgentStreamed`) spans.
- Migrating app-side `ai_evaluations` (LLM-as-a-judge) to Braintrust online scoring.
- User feedback API, datasets, offline experiments / CI evals.

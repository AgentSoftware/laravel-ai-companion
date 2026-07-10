# Braintrust user feedback (thumbs up/down) — design

## Problem

Consuming apps (e.g. Spectre Websites) let end users mark an AI-driven flow — like an onboarding session — as good or bad via a thumbs up/down control. We want that signal to land in Braintrust as a score against the correct logged trace, so it shows up alongside the trace in the Braintrust UI and can be charted/alerted on like online scores.

The trace for a business flow is already shipped to Braintrust as a root span, deterministically keyed by the `ai_usage_source_id` / `ai_usage_source_model` values the app sets via Laravel `Context` before making AI calls for that flow (see `docs/superpowers/specs/2026-06-10-braintrust-exporter-design.md`). We want the feedback call to reuse that same identity — the app should not need to look up or store any Braintrust-specific id.

## Scope

- Feedback attaches to the **whole session** (the root trace row), not to individual agent steps within it. Confirmed with the user: their thumbs up/down UI (see onboarding session screenshot) operates at the session level.
- Feedback is a boolean signal (`good: true|false`) mapped to a numeric Braintrust score, plus an optional freeform comment.
- The call is **synchronous** — a direct HTTP request, not queued. This is a discrete, foreground user action (not part of the "never throw into an AI call" tracing pipeline), so the app should get an immediate success/failure result and can display an error if the call fails.
- If Braintrust isn't enabled/configured, the method throws rather than silently no-oping, so misconfiguration isn't hidden.
- Out of scope: per-step feedback, configurable score names, richer feedback fields (`expected`, `tags`, `metadata`), queued/async dispatch. These can be added later if a real need arises.

## Braintrust API facts (verified against docs + OpenAPI spec)

- `POST /v1/project_logs/{project_id}/feedback` attaches feedback to an **existing logged row**, referenced purely by that row's `id` — the same `id` used when the row was inserted via `POST /v1/project_logs/{project_id}/insert`. No `span_id`/`root_span_id`/`span_parents` needed.
- Request body: `{"feedback": [{"id": "...", "scores": {"<name>": 0.0-1.0}, "comment": "...", "source": "app"|"api"|"external"}]}`. `scores` values are merged into existing scores for that row, not overwritten wholesale.
- Response: `{"status": "success"}` on success.
- Analogous endpoints exist for experiments/datasets (`/v1/experiment/{id}/feedback`, `/v1/dataset/{id}/feedback}`) — irrelevant here since this targets live project logs.

## Architecture

```
App (thumbs up/down) → AiFeedback::record($sourceModel, $sourceId, good: true, comment: '...')
                          → BraintrustFeedbackClient
                              → resolves Braintrust project id (shared cache/lookup with BraintrustApi)
                              → recomputes deterministic root span id (shared with SpanBuilder)
                              → POST /v1/project_logs/{project_id}/feedback
```

This mirrors the existing `Eval/Scaffolding/BraintrustApi` precedent: a Braintrust-aware helper class used directly (not behind a swappable `TraceExporter`-style contract). Only Braintrust is a feedback target today, so a contract abstraction would be premature — it can be extracted later if a second destination is ever needed. This does not violate the "BraintrustExporter is the only Braintrust-aware class" rule from the tracing architecture, since that rule scopes the neutral span pipeline (`SpanBuilder` → `ShipSpans` → `TraceExporter`); feedback is a separate, non-pipeline concern, same as `BraintrustApi` already is for eval scaffolding.

## Components

- **`SpanBuilder::rootSpanId(string $sourceModel, string $sourceId): string`** (new public static method) — extracted from the existing private `rootId()` UUIDv5 computation (`Uuid::uuid5(Uuid::NAMESPACE_URL, "ai-companion:{$sourceModel}:{$sourceId}")`), so the tracing pipeline and the feedback client compute identical ids from the same inputs and can never drift apart. The existing private `rootId()` delegates to this new method using the current `Context` values.
- **`Braintrust/InteractsWithBraintrustApi`** (new trait, extracted) — the `client()`, `projectId()` (forever-cached), and `request()` (421 EU-data-plane handling) logic currently inlined as private methods on `BraintrustApi`. A trait rather than a constructor-injected service, because `BraintrustApi` is instantiated directly (`new BraintrustApi()`) at several existing call sites with no container DI — a trait shares the logic with zero changes to those call sites. Used by both `BraintrustApi` and the new `BraintrustFeedbackClient`, so there's one cache key and one error-handling path.
- **`Feedback/BraintrustFeedbackClient`** (new) — `record(string $sourceModel, string $sourceId, bool $good, ?string $comment = null): void`.
  - Throws `BraintrustFeedbackException` if `config('ai-companion.braintrust.enabled')` is false or the API key is missing — checked before any HTTP call.
  - Computes `$spanId = SpanBuilder::rootSpanId($sourceModel, $sourceId)`.
  - Resolves the project id via the shared `InteractsWithBraintrustApi` trait.
  - POSTs `{"feedback": [{"id": $spanId, "scores": {"user_feedback": $good ? 1.0 : 0.0}, "comment": $comment, "source": "app"}]}` to `/v1/project_logs/{project_id}/feedback`.
  - Throws `BraintrustFeedbackException` on non-2xx response, surfacing the response body/status (reusing the existing 421 EU-data-plane hint from `BraintrustApi`'s error handling).
- **`Facades/AiFeedback`** (new) — thin facade resolving `BraintrustFeedbackClient` from the container. `AiFeedback::record($sourceModel, $sourceId, good: true, comment: null)`.
- **`Exceptions/BraintrustFeedbackException`** (new) — thrown for misconfiguration and HTTP failure, as above.

No new config keys — reuses `ai-companion.braintrust.{enabled,api_key,api_url,project}`.

## Data flow

1. App calls, using the same `$sourceModel`/`$sourceId` it already sets via `Context` for that flow:
   ```php
   AiFeedback::record('OnboardingSession', $onboardingSession->uuid, good: true, comment: null);
   ```
2. `BraintrustFeedbackClient::record()` validates config is present (throws if not), computes the deterministic span id, resolves the project id (cached), and POSTs to the feedback endpoint.
3. 2xx response → returns void. Non-2xx → throws `BraintrustFeedbackException` with the error surfaced.

## Error handling

- Disabled/misconfigured Braintrust → throws before any network call.
- HTTP failure (including the case where the target row was never actually shipped to Braintrust, e.g. feedback called for a session that never made an AI call) → throws with the Braintrust error body surfaced, so the app can decide how to handle/display it.
- No silent swallowing — this is a foreground user action, distinct from the tracing pipeline's "never throw into an AI call" guarantee.

## Testing

- `Http::fake` asserting exact request shape (`id`, `scores.user_feedback`, `comment`, `source`) against `/v1/project_logs/{project}/feedback`.
- Assert `good: true`/`good: false` map to score `1.0`/`0.0`.
- Assert the computed span id for a given `$sourceModel`/`$sourceId` matches the root span id produced by `SpanBuilder` for the same values, so the two code paths can't drift apart.
- Assert `BraintrustFeedbackException` is thrown when `braintrust.enabled` is false, when the API key is missing, and on a non-2xx HTTP response.
- Assert project id resolution is cached — a single `/v1/project` call across repeated `record()` calls (and shared cache key with `BraintrustApi` where both are exercised).

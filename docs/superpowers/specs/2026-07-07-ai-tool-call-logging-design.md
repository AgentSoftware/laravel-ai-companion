# AI Tool Call Logging — Design

## Problem

`ai_response_logs` records the prompt/response for an agent invocation, but not which tools were called during that invocation, with what input, and what output. There's no way to answer "what tools fired for this response, and what did they see/return?"

## Solution

Add a new `ai_tool_calls` table that records one row per tool invocation, hard-linked to its parent `ai_response_logs` row.

### Schema: `ai_tool_calls`

| column | type | notes |
|---|---|---|
| `id` | UUID PK | |
| `ai_response_log_id` | UUID, FK → `ai_response_logs.id`, cascade on delete | |
| `tool_invocation_id` | string, nullable, unique | from the `laravel/ai` event, for idempotency/debugging |
| `tool` | string, indexed | tool class name |
| `input` | JSON | `$event->arguments` |
| `output` | JSON, nullable | `$event->result` |
| `duration_ms` | unsigned int, nullable | derived by pairing `InvokingTool` → `ToolInvoked` |
| `created_at` / `updated_at` | timestamps | |

No `status`/`error` columns: `laravel/ai`'s `ToolInvoked` event only fires on successful tool execution — there is no corresponding failure event (same gap that already exists for `AgentPrompted` on hard agent failures, per `TraceTimings`' orphaned-entry handling). Adding status/error columns that would always read "success"/null is dead weight.

### Model: `AiToolCall`

- `src/Models/AiToolCall.php`, uses `HasUuids`, casts `input`/`output` to array.
- `belongsTo(AiResponseLog::class)`.
- Add inverse `hasMany(AiToolCall::class)` to `src/Models/AiResponseLog.php`.

### Config

New key in `config/ai-companion.php`:

```php
'tool_call_logs' => [
    'enabled' => env('AI_COMPANION_TOOL_CALL_LOGS_ENABLED', false),
],
```

Opt-in, consistent with `braintrust.enabled` — not always-on like token usage tracking, since it depends on the response-log middleware being active for the agent.

### Listener: `RecordAiToolCall`

New event subscriber (pattern mirrors `Tracing/Listeners/ExportTrace`), registered on `InvokingTool` and `ToolInvoked`, only bound in the service provider when `tool_call_logs.enabled` is true.

- `InvokingTool` → `TraceTimings::start("tool:{$event->toolInvocationId}", microtime(true))`. Reuses the existing `TraceTimings` singleton (already used by `ExportTrace` for the same event pair) rather than building new pairing state.
- `ToolInvoked` →
  1. `$startedAt = TraceTimings::pull("tool:{$event->toolInvocationId}")`; compute `duration_ms` if present, else null.
  2. Look up `AiResponseLog::where('invocation_id', $event->invocationId)->first()`.
  3. If found, create an `AiToolCall` row (`tool` = `$event->tool::class`, `input` = `$event->arguments`, `output` = `$event->result`).
  4. If not found (middleware not enabled for this agent, or log not yet/ever persisted), silently skip — no exception, no log noise. Matches the package-wide rule that listeners must never throw into an AI call.

The whole handler body runs through `rescue()` (or try/catch swallow + optional `report()`), consistent with `RecordAgentTokenUsage` and `ExportTrace`.

### Out of scope

- Backfilling tool calls for response logs that predate this feature.
- Capturing failed tool invocations (no upstream event exists to observe them).
- Any UI/dashboard for browsing tool calls — this is storage only, consumption is left to the app (e.g. via Eloquent, or joined into Braintrust debugging).

## Testing

- Migration test: table/columns/FK exist.
- Listener test: fake the `InvokingTool`/`ToolInvoked` events (or dispatch real ones against a fake response log), assert a row is created with correct `ai_response_log_id`, `input`, `output`, `duration_ms`.
- Listener test: `ToolInvoked` with no matching `ai_response_logs` row → no `AiToolCall` row created, no exception thrown.
- Config test: listener not registered when `tool_call_logs.enabled` is false.

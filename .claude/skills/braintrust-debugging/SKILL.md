---
name: braintrust-debugging
description: Use when debugging AI agent output, prompts, tool calls, token usage, or eval scores — e.g. a run produced wrong content, an agent ignored instructions, a tool wasn't called, an online scorer isn't firing, or you need to see what an AI agent actually received or returned. Queries Braintrust traces via the Braintrust MCP server (or REST API fallback) and joins them to the ai_response_logs and ai_token_usages tables this package writes.
---

# Braintrust Debugging

**When NOT to use this:** for non-AI bugs (use `superpowers:systematic-debugging`); for writing package code (CLAUDE.md covers the architecture); for queue issues where spans never arrive because workers are down (that's the consuming app's queue setup). This skill is for finding out what an AI agent actually received, did, and returned.

Every AI agent invocation traced by this package is exported to Braintrust as a span; the full prompt, output, errors, timings, and token metrics live there. The consuming app's `ai_response_logs` and `ai_token_usages` tables (also owned by this package) are the quick local index.

## Step 0: Check for the Braintrust MCP first

Before reaching for curl, check whether the Braintrust MCP server is connected — use ToolSearch for `mcp__braintrust__` tools (e.g. `sql_query`). If found, **prefer the MCP**: it's already authenticated, needs no key handling, and runs the same BTQL.

| MCP tool | Use for |
|---|---|
| `mcp__braintrust__sql_query` | All trace queries — SQL-style structured args (see below) |
| `mcp__braintrust__list_recent_objects` / `resolve_object` | Finding the project and recent logs without knowing IDs |
| `mcp__braintrust__generate_permalink` | Sharable Braintrust UI link to a span/trace for the user |
| `mcp__braintrust__infer_schema` | Discovering queryable fields |
| `mcp__braintrust__search_docs` | Braintrust/BTQL syntax questions |

`sql_query` does NOT take a raw BTQL string — it takes structured SQL-style arguments:

```json
{
  "select": "span_id, span_attributes, created, error",
  "object_type": "project_logs",
  "object_ids": ["<PROJECT_ID>"],
  "where": "span_attributes.type = 'task'",
  "order_by": "created DESC",
  "limit": 10,
  "preview_length": 300
}
```

- `select`, `object_type`, `object_ids` are required; `object_type` is `project_logs` for traces.
- `preview_length` truncates each field — use a small value (150–300) for overview queries, omit it only when pulling one span's full `input`/`output`.
- `shape: "traces"` returns whole traces instead of individual spans.
- The MCP has a SQL linter: `span_id = root_span_id` is **rejected** as inefficient — filter root spans with `span_attributes.type = 'task'` instead. The same `where`/`order_by` expressions otherwise mirror the BTQL `filter:`/`sort:` clauses below.

If the MCP is not connected, either add it (key from the consuming app's `.env`; EU orgs use the EU host):

```bash
claude mcp add --scope user --transport http braintrust https://api-eu.braintrust.dev/mcp \
  --header "Authorization: Bearer $(grep '^BRAINTRUST_API_KEY=' .env | cut -d= -f2)"
```

(requires a session restart to pick up) — or fall back to the REST API below, which works immediately.

## REST fallback setup (run once per session)

All values come from the consuming app's `.env` (the package reads the same keys via `config('ai-companion.braintrust.*')`):

```bash
KEY=$(grep '^BRAINTRUST_API_KEY=' .env | cut -d= -f2)
URL=$(grep '^BRAINTRUST_API_URL=' .env | cut -d= -f2)   # EU orgs: https://api-eu.braintrust.dev — api.braintrust.dev 421s
PROJECT=$(grep '^BRAINTRUST_PROJECT=' .env | cut -d= -f2)  # defaults to app.name when unset
```

Resolve the project ID:

```bash
curl -s "$URL/v1/project?project_name=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote('$PROJECT'))")" -H "Authorization: Bearer $KEY"
# → .objects[0].id
PID=<that id>
```

From inside a consuming app you can skip all of this: `AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi` already wraps BTQL queries, function upserts, invokes, and online-rule management with the right auth, host, and timeouts — `php artisan tinker` + `new BraintrustApi()` is often the fastest path.

## How the data is linked

| Braintrust field | Meaning | Local DB link |
|---|---|---|
| `span_id` / `id` | One agent/tool/LLM invocation | `ai_response_logs.invocation_id` |
| `root_span_id` | The whole trace (one business flow, grouped by the `Context` source keys) | root span's id = root agent's `invocation_id` |
| `span_attributes.name` | Agent/tool class basename (from `class_basename($agent)` in `SpanBuilder`) | `ai_response_logs.agent` (class FQN) |
| `span_attributes.type` | `task` (root), `llm` (agent call), `tool` (tool call) | — |
| `input` / `output` | Agent spans: `input = {prompt, instructions}`; output = text or structured array | `ai_response_logs.prompt` / `response` |
| `metrics` | tokens, start/end | `ai_token_usages` (joined via `source_id`) |
| `scores` | Offline experiment scores live on experiments; online scores land on the span at ingest | — |

The local tables are the quick index (status, agent, timestamps); Braintrust holds the full trace tree with every nested tool call.

## Querying traces — BTQL

BTQL is pipe-style: `select: ... from: project_logs('$PID') filter: ... sort: ... limit: N`. Via the MCP, translate each query into `sql_query` structured args (Step 0); via REST, POST to `$URL/btql` with `{"query": "<btql>"}` as shown below. **The plain `/v1/project_logs/{id}/fetch` endpoint has NO filter parameter — always use BTQL for filtered reads.**

**1. List recent runs (root spans only):**

```bash
curl -s -X POST "$URL/btql" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d "{\"query\": \"select: span_id, span_attributes, created, error from: project_logs('$PID') filter: span_id = root_span_id sort: created desc limit: 10\"}"
```

**2. Get the full span tree for one run** (use a `root_span_id` from step 1, or an `invocation_id` from `ai_response_logs` for the root agent):

```bash
curl -s -X POST "$URL/btql" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d "{\"query\": \"select: span_id, span_parents, span_attributes, created, error, metrics from: project_logs('$PID') filter: root_span_id = '<ROOT_ID>' sort: created asc limit: 1000\"}"
```

Keep `input`/`output` OUT of this overview query — prompts are huge. Scan the tree first (names, types, errors, timings), then pull full payloads for only the suspicious spans.

If the row count equals the limit, the trace is truncated — increase the limit and re-run (a large pipeline run can be 300+ spans). Note the root span's `created` is set near run *completion*, not start — use the earliest child span for the run's start time.

**3. Inspect a specific span's prompt and output:**

```bash
curl -s -X POST "$URL/btql" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d "{\"query\": \"select: input, output, error, metadata, metrics from: project_logs('$PID') filter: span_id = '<SPAN_ID>' limit: 1\"}"
```

**4. Find recent invocations of a specific agent by name:**

```bash
curl -s -X POST "$URL/btql" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d "{\"query\": \"select: span_id, root_span_id, created, error from: project_logs('$PID') filter: span_attributes.name = 'PageContentWriterAgent' and created > '2026-06-11' sort: created desc limit: 10\"}"
```

Dates are ISO strings compared with `>`/`<`. Other useful filters: `error != null` (failed spans), `span_attributes.type = 'tool'`, `scores.<name> IS NULL` (spans a scorer hasn't touched), `span_parents includes '<span_id>'` (a span's direct children — how the package finds an invocation's tool calls). Array membership is `includes` — `IN` and `contains()` do not parse.

## Debugging workflow

1. **Anchor the run.** From the user's report ("the run we just did"), find the run in `ai_response_logs` (status, agent, `created_at`, `invocation_id`) or list recent root spans (query 1).
2. **Map the trace.** Pull the span tree (query 2). Look at ordering, which tools fired (and which didn't), error fields, and durations/token counts in `metrics`.
3. **Zoom in.** Pull `input`/`output` for the span that produced the bad output (query 3). Compare what the agent was actually told (instructions + tool results in `input`) against what it returned.
4. **Cross-reference locally.** `ai_response_logs` rows stuck in `running` or marked failed show where the pipeline died; `ai_token_usages` shows per-agent model + token cost (`source_id` links usage rows to their source).
5. **Diagnose.** Typical findings: missing context in the prompt (an upstream tool returned nothing), the agent asking the user for input instead of acting (conversational output in a pipeline — pair with `ToolUsageScorer` to catch it continuously), truncated output (check `metrics` output tokens vs model max), or a tool erroring silently (`error` field on the tool span).
6. **Refute before concluding.** Before reporting, check one alternative explanation: if you blame the prompt, confirm the input actually lacked the context (don't infer it); if you blame the model, confirm an identical earlier run succeeded with the same input. A plausible story that skips this step is a guess.

## Diagnosis report — evidence required

Every conclusion in your report must be anchored to trace data you actually pulled, not inference:

- **Cite the span:** each claim names the `span_id` (and agent/tool name) it comes from, with a short verbatim quote from the span's `input`/`output`/`error`.
- **Generate a permalink** for the key span(s) via `mcp__braintrust__generate_permalink` so the user can open the exact trace in the Braintrust UI.
- **State what you did NOT check** (spans not pulled, runs not compared) instead of letting the report imply full coverage.
- Lead with the answer: root cause first, evidence after, suggested fix last.

## Output handling

Responses are JSON: `{"data": [...], "schema": {...}}`. Payloads are large — pipe through `python3 -c` or `jq` to summarise rather than dumping raw to the terminal. Fields not selected (or null) are absent from rows — always use `.get()` rather than direct key access.

## Scorers — prefer the package commands

This package owns the scorer lifecycle — reach for raw REST only when the commands can't do it:

- `php artisan ai:scaffold-eval` scaffolds JS scorer files (`resources/ai/scorers/*.js`) and the eval target.
- `php artisan ai:eval <key>` runs them locally via Node (zero Braintrust contact).
- `php artisan ai:publish-eval` creates/updates the Braintrust function by slug, smoke-tests it **in the real sandbox**, and reconciles the online scoring rule. It sets `function_type: 'scorer'` and node 20 for you.

### Scorer runtime facts (live-verified 2026-07 on node 20)

- The runtime expects a function named **`handler`** — no `export`, no `module.exports`. `async function handler({ output, input, expected }) { return { score: 0-1, metadata: {...} }; }`. Adding any export statement causes a `ReferenceError`.
- `fetch` IS available in the sandbox; **`AbortSignal.timeout` is NOT** — a scorer using it fails every call (the publish smoke test catches this).
- Historical gotcha (node 18 era): optional chaining `?.` / nullish coalescing `??` triggered a transpiler that crashed with `exports is not defined`. Our node-20 scorers use modern syntax without issue, but if a scorer crashes that way, suspect the transpiler and fall back to `||`/`&&`.
- Returning `null` or a non-numeric score shows "This score span did not output a numeric value" in the UI — return `0` for failure cases. (`ai:publish-eval`'s smoke test rejects non-numeric scores before the rule goes live.)
- Online scoring rules (`/v1/project_score`, `score_type: 'online'`) match spans by **exact** name via `apply_to_span_names` — the publish command sends both `StudlyKey` and `StudlyKeyAgent` for this reason. A rule with a wrong span name silently scores nothing.
- Online scores appear on spans at **ingest** — pre-existing spans are never retroactively scored, and ingest lag is ~30–60s.

## Common mistakes

- **Skipping the MCP check:** don't hand-roll curl when `mcp__braintrust__sql_query` is connected — check with ToolSearch first.
- **Wrong region:** the default `api.braintrust.dev` returns `421 DataPlaneRedirectError` for EU-pinned orgs — always read `BRAINTRUST_API_URL` from `.env`.
- **Selecting `input`/`output` in list queries:** prompts can be 50k+ tokens; always scope payload selects to a single `span_id`.
- **Confusing span_id with root_span_id:** `span_id` is one invocation; `root_span_id` groups the whole run. A root span has `span_id = root_span_id`.
- **Quoting:** BTQL string literals use single quotes inside the JSON-escaped query string.
- **Tracing lag:** spans ship via a queued job (`AI_COMPANION_BRAINTRUST_QUEUE`) — a run that just finished may take a moment to appear; check the app's queue workers if spans never arrive.
- **Scorer not appearing in the UI's scorer picker:** created without `function_type: "scorer"` — `ai:publish-eval` sets it; if created by hand, PATCH it in.
- **Scorer crashes with `exports is not defined`:** an export statement or (older runtimes) transpiled modern syntax — remove exports; the runtime finds `handler` by name.

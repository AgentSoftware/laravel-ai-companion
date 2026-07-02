# Design: `ai:eval:scaffold` — interactive eval scaffolding command

**Date:** 2026-07-02
**Status:** Approved

## Goal

A single interactive artisan command, `php artisan ai:eval:scaffold`, that guides a consuming
app from "we have an Agent and some historical AI traffic" to "we have a runnable eval": a
dataset JSON file, an `EvalTarget` class wired to the agent, and scorers — with minimal typing.

## Decisions made during brainstorming

- **Scaffolding intelligence:** reflection-based. The command introspects the chosen Agent's
  constructor and generates the row → constructor-arg mapping. No LLM at scaffold time.
- **Row shape:** raw/predictable. Each dataset row is
  `{"prompt": <input>, "expected"?: <output>, ...flattened metadata}`. No interactive
  per-field mapping (may be added later if the raw shape proves painful).
- **Scorers:** multiselect from the package built-ins (`MatchScorer`, `LlmJudgeScorer`,
  `RangeScorer`, `ToolRoutingScorer`); `LlmJudgeScorer` rubric text is asked for inline and
  baked into `scorers()`; optional custom scorer names generate TODO stubs in the app.
- **Command shape:** one wizard command with skippable steps (not split dataset/target
  commands).

## Wizard flow

Built on Laravel Prompts.

1. **Pick agent** — discover classes implementing `Laravel\Ai\Contracts\Agent` in the host
   app's PSR-4 autoload roots (composer autoload config), presented via `search()`. Eval
   `key()`/`label()` default from the class name (`PagePlannerAgent` → `page-planner`),
   both editable.
2. **Pick data source** — `select()`:
   - Existing Braintrust dataset
   - Recent Braintrust logs (project logs / spans we shipped)
   - `ai_response_logs` table
   - Skip (dataset file already exists)
3. **Fetch & filter** — Braintrust sources reuse the exporter config (`api_key`, `api_url`,
   project name) and the REST fetch endpoints. Log-based sources prompt for row count and an
   agent filter (spans carry agent metadata; `ai_response_logs` stores the agent class).
   `multiselect()` checkboxes choose what lands in each row: prompt input, output
   (as `expected`), flattened metadata keys.
4. **Write JSON** — rows written to `database/eval-datasets/<key>.json`. Confirm before
   overwriting an existing file.
5. **Scorers** — built-in multiselect, inline LlmJudge rubric, then optional custom scorer
   names → stub classes in `app/Ai/Eval/Scorers/`, all wired into the generated `scorers()`.
6. **Generate `EvalTarget`** — stub rendered to `app/Ai/Eval/Targets/<Agent>EvalTarget.php`:
   - `promptInput()` returns `(string) ($row['prompt'] ?? '')`
   - `agent()` news up the agent; each scalar constructor param maps from
     `$row['snake_case_name'] ?? <default>`
   - non-scalar params (objects/interfaces) are emitted with a
     `/** TODO: resolve from container or row */` comment instead of failing
   Finish by printing the `php artisan ai:eval` run instructions.

## Components (all inside the package)

| Component | Responsibility |
|---|---|
| `Eval/Commands/ScaffoldEvalCommand.php` | Wizard orchestration only; no business logic |
| `Eval/Scaffolding/AgentDiscovery.php` | Find Agent implementations in the host app |
| `Eval/Scaffolding/DatasetSource.php` (contract) | `fetch(...): array` of normalized rows |
| `Eval/Scaffolding/BraintrustDatasetSource.php` | Pull rows from an existing Braintrust dataset |
| `Eval/Scaffolding/BraintrustLogsSource.php` | Pull rows from recent Braintrust project logs |
| `Eval/Scaffolding/ResponseLogSource.php` | Pull rows from the `ai_response_logs` table |
| `Eval/Scaffolding/TargetGenerator.php` | Render the `EvalTarget` stub (reflection mapping) |
| `Eval/Scaffolding/ScorerGenerator.php` | Render custom scorer stubs |
| `stubs/` | Plain token-replacement stub templates (no template engine) |

Braintrust sources are the only Braintrust-aware scaffolding classes, mirroring the
exporter isolation rule.

## Error handling

Fail soft, always with an actionable message:

- Missing Braintrust config → name the env vars to set.
- `421 DataPlaneRedirectError` → tell the user to set
  `BRAINTRUST_API_URL=https://api-eu.braintrust.dev` (EU-pinned orgs).
- No agents discovered → explain which paths were scanned.
- Non-instantiable constructor params → generate with a TODO comment, never abort.
- Existing files (dataset JSON, target, scorers) → confirm before overwrite.

## Testing

Pest + Testbench, per house conventions:

- `Http::fake` for both Braintrust sources; never hit the real API.
- Sqlite fixture rows for `ResponseLogSource`.
- Snapshot-style string assertions on `TargetGenerator`/`ScorerGenerator` output
  (including the reflection mapping and TODO-comment paths).
- Command-level test driving the Prompts interactions end-to-end against fakes.

## Out of scope (YAGNI)

- Interactive per-field row mapping (revisit if raw rows hurt).
- LLM-assisted scaffolding.
- Automatic 421 redirect following (separate improvement, noted in CLAUDE.md).

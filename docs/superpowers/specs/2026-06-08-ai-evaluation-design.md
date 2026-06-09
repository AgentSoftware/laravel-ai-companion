# AI Evaluation Module — Design Spec

**Date:** 2026-06-08
**Package:** `agentsoftware/laravel-ai-companion`
**Status:** Approved, ready for implementation planning

---

## Problem

We have multiple Laravel apps running large AI agent pipelines (e.g. the 8-stage `StartOnboardingPipeline` in spectre-websites with ~15 agents). We change prompts frequently but have no way to verify whether an agent is doing its job correctly other than manually running the pipeline end-to-end. We need a systematic way to evaluate agent output quality, score it, and surface what to fix.

We already have `laravel-ai-companion` logging every agent call (prompt, response, tokens, metadata) to `ai_response_logs`. The evaluation module sits on top of that data.

---

## Goals

- Score past agent responses 0–100 with a per-criterion breakdown and written summary
- Work out of the box with zero config on every agent already being logged
- Allow explicit rubrics on specific agents where precision matters
- Run manually via Artisan command — no automatic background evaluation for now
- Reusable across all products that install `laravel-ai-companion`

---

## Architecture

The evaluation module lives inside `laravel-ai-companion` as a new opt-in feature. No new package. Follows the same opt-in pattern as response logging.

```
src/
  Evaluation/
    EvaluationRunner.php          # Orchestrates: fetch logs → judge → store
    Judge/
      LlmJudge.php                # Calls the LLM, parses structured JSON response
    Scorers/
      Scorer.php                  # Abstract base — extend per agent
      AutoInferredScorer.php      # Default: infers criteria from system prompt
    Results/
      EvaluationResult.php        # Value object: overall score + criteria + summary
      CriterionResult.php         # One scored criterion
  Models/
    AiEvaluation.php              # Eloquent model, belongsTo AiResponseLog
  Console/
    EvaluateCommand.php           # php artisan ai:evaluate
database/
  migrations/
    create_ai_evaluations_table.php
```

`AiResponseLog` gets a `hasMany(AiEvaluation::class)` relation.

---

## Prerequisite: Store Agent Instructions in `ai_response_logs`

The LLM judge needs the agent's system prompt to evaluate against. Currently `LogAiResponse` does not capture it. The migration and middleware must be updated to add an `instructions` column:

```sql
-- new migration in laravel-ai-companion
ALTER TABLE ai_response_logs ADD COLUMN instructions longtext NULLABLE;
```

`LogAiResponse` captures it from `$prompt->agent->instructions()` at call time. This is important — we store it at call time so historical evaluations use the instructions that were actually in effect, not whatever the agent's instructions say today.

---

## Database Schema

```sql
ai_evaluations
  id                  uuid, primary
  ai_response_log_id  uuid, FK → ai_response_logs.id, indexed
  agent               string, indexed
  scorer              string, nullable       -- scorer class used; null = auto-inferred
  overall_score       smallint (0–100)
  criteria            json
  summary             text
  judge_model         string
  created_at
  updated_at
```

Example `criteria` column:
```json
[
  {"name": "accuracy",      "score": 82, "feedback": "Content reflects company info accurately but omitted the founding year."},
  {"name": "completeness",  "score": 60, "feedback": "Missing a CTA section required by the agent instructions."},
  {"name": "tone",          "score": 91, "feedback": "Professional and on-brand throughout."}
]
```

---

## Scorers

### Default: `AutoInferredScorer`

When no explicit scorer is registered for an agent, the judge receives the agent's system prompt (stored in the log) and infers 3–5 appropriate criteria itself. Zero setup — works immediately on every logged agent.

### Explicit: Extend `Scorer`

For agents needing precise, consistent rubrics:

```php
class ContentWriterAgentScorer extends Scorer
{
    protected string $agent = ContentWriterAgent::class;

    protected array $criteria = [
        'accuracy'        => 'Does the content accurately reflect the company details in the prompt? No invented facts.',
        'completeness'    => 'Are all required sections present — hero, about, services, CTA?',
        'tone'            => 'Is the writing professional, confident, and free of filler phrases?',
        'no_placeholders' => 'Does the response avoid placeholder text like [COMPANY NAME] or [INSERT HERE]?',
    ];
}
```

Register in `config/ai-companion.php`:

```php
'evaluation' => [
    'enabled' => true,
    'model'   => env('AI_EVALUATION_MODEL', 'claude-haiku-4-5-20251001'),
    'scorers' => [
        ContentWriterAgentScorer::class,
        NavigationStructureAgentScorer::class,
    ],
],
```

The runner checks the config first — explicit scorer if found, `AutoInferredScorer` otherwise.

---

## The LLM Judge

A structured Laravel AI agent inside the package. Prompt shape:

```
You are an expert AI evaluator. Score the following agent response honestly and critically.

--- AGENT INSTRUCTIONS ---
{system prompt from the log}

--- USER INPUT ---
{prompt from the log}

--- AGENT RESPONSE ---
{response from the log}

--- EVALUATION CRITERIA ---
{explicit criteria list, OR: "Infer 3–5 appropriate criteria from the agent's instructions above."}

Return ONLY valid JSON in this exact shape:
{
  "overall_score": 0-100,
  "criteria": [
    {"name": "...", "score": 0-100, "feedback": "one sentence"}
  ],
  "summary": "2-3 sentence paragraph"
}
```

Uses structured output (JSON schema enforced via Laravel AI SDK). If parsing fails for a log, it is skipped and flagged in terminal output — it never crashes the full run.

Logs captured before the `instructions` column was added will have `instructions = null`. In that case the judge omits the agent instructions section and falls back to evaluating purely from the user prompt and response — still useful, just less precise. These logs are not skipped.

---

## The Command

```bash
# No arguments → interactive multi-select picker of distinct agents from ai_response_logs
php artisan ai:evaluate

  Which agents would you like to evaluate?
  > [x] ContentWriterAgent
    [ ] NavigationStructureAgent
    [x] CompanyResearchAgent
    [ ] HeroSynthesisAgent

# Skip the picker
php artisan ai:evaluate --agent=ContentWriterAgent

# Time window
php artisan ai:evaluate --since=7d
php artisan ai:evaluate --since=2026-06-01

# Sample size cap
php artisan ai:evaluate --limit=20

# Re-score logs that already have an evaluation (e.g. after changing a scorer)
php artisan ai:evaluate --re-run
```

### Terminal Output

```
Evaluating ContentWriterAgent (12 logs)...

 ✓  log a1b2c3  overall: 84   accuracy:88  completeness:72  tone:91  no_placeholders:100
 ✓  log d4e5f6  overall: 61   accuracy:70  completeness:45  tone:68  no_placeholders:100
 ✗  log g7h8i9  FAILED — judge returned invalid JSON, skipped

Summary
  ContentWriterAgent   avg: 72/100   12 evaluated, 1 skipped
  Lowest criterion: completeness (avg 51) — consider reviewing the section requirements in the prompt.
```

The summary line surfaces the weakest criterion across the batch — the primary signal for which part of a prompt to improve.

---

## Configuration

All evaluation config lives under the `evaluation` key in `config/ai-companion.php`:

```php
'evaluation' => [
    'enabled' => env('AI_EVALUATION_ENABLED', true),
    'model'   => env('AI_EVALUATION_MODEL', 'claude-haiku-4-5-20251001'),
    'scorers' => [],
],
```

---

## What This Is Not (v1 Scope)

- No automatic/real-time evaluation on every response — manual runs only
- No web UI or dashboard — terminal output and queryable `ai_evaluations` table
- No Braintrust integration — self-contained, no external dependency
- No deterministic scorers (regex/schema) — LLM judge only for now

These are all valid v2 additions once the core loop is proven useful.

---

## Cross-Product Usage

Any app that installs `laravel-ai-companion` gets the evaluation module. Scorer classes live in each app's codebase and are registered via that app's config. The package ships no app-specific scorers — only the abstract base and the auto-inferred default.

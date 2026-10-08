# Upgrade Guide

## Upgrading To 5.2 From 5.1

Companion 5.2 requires `laravel/ai` 1.2 or later, for its `Classification` API. There are no new migrations, and existing `EvalTarget` implementations are unchanged.

### Added

- `Eval\Contracts\ClassifierEvalTarget`: evaluate a `Laravel\Ai\Classification` over a dataset with `ai:eval`, against the provider and model from `--provider` / `--model`. Rows map to an `Eval\ClassificationCase` (state, questions, expected answers, attachments).
- `Eval\Scorers\ChoiceAnswerScorer` and `Eval\Scorers\BooleanAnswerScorer`, including must-catch / must-pass scores driven by `Eval\ExpectedAnswerTag`.
- `--dataset=braintrust:<name>` loads an eval dataset from the configured Braintrust project at run time, and `braintrust_attachment` references in any dataset row are downloaded and replaced with SDK files (`Eval\BraintrustAttachments`).
- `Score::$blocking`: a measured blocking score below 1.0 fails the eval command.
- The eval command prints each score's mean and a confusion matrix for scores that record `confusion` metadata.

## Upgrading To 5.1 From 5.0

There are no new migrations, and no action is needed unless you read `ExperimentEventData::$expected` directly.

### Added

- `Eval\Contracts\FiltersDatasetRows`: an opt-in contract for an `EvalTarget` to exclude dataset rows. It runs before `--tag` and `--limit`, and the runner reports `Skipped N rows (excluded by target).`

### Fixed

- A string `expected` on a dataset row was dropped from exported experiment rows. It is now exported alongside array values.

### `ExperimentEventData::$expected` Type Widened

**Likelihood Of Impact: Low**

The property is now `array|string|null` (was `array|null`). Code that reads it and assumes an array must handle a string.

## Upgrading To 5.0 From 4.x

Companion 5.0 requires `laravel/ai` 1.x. Stay on companion 4.x if you are still on `laravel/ai` 0.9–0.11. Upgrade `laravel/ai` first by following [its upgrade guide](https://github.com/laravel/ai/blob/1.x/UPGRADE.md).

```bash
composer require agentsoftware/laravel-ai-companion:^5.0 laravel/ai:^1.0
```

There are no new migrations.

### Token Counts Now Include Cached And Reasoning Tokens

**Likelihood Of Impact: High**

`laravel/ai` 1.0 reports the provider's complete counts: input includes cache-read and cache-write tokens, and output includes reasoning tokens. In 0.x these were excluded. The companion stores what the SDK reports, so from 5.0:

- `ai_token_usages.input_tokens` includes `cache_read_tokens` and `cache_write_tokens`, which remain stored as subsets. `output_tokens` includes reasoning tokens.
- Braintrust span metrics `prompt_tokens`, `completion_tokens`, and `tokens` include the same categories, as do offline eval experiment metrics.
- Cache and reasoning counts the provider does not report are stored as `0` in `ai_token_usages` and left out of Braintrust span metrics, rather than shipped as `0`.

Rows written before the upgrade keep the old meaning, so `AiUsage` totals and any cost reports that span the upgrade will mix the two. For cost, apply the base rate to `input_tokens - cache_read_tokens - cache_write_tokens` on new rows, and the cache rates to the cache columns.

### Response Logging Is Event-Driven

**Likelihood Of Impact: Low**

Agent middleware now wraps each generation step rather than the whole run, and a step carries neither the agent nor the prompt. `LogAiResponse` is still how an agent opts in, and needs no change on your agents. The logging itself now happens in a `RecordAiResponseLog` subscriber driven by the `PromptingAgent`, `AgentPrompted`, and `AgentFailed` events. Behaviour changes:

- Still one `ai_response_logs` row per run, including multi-step runs and runs that fail over between providers.
- `invocation_id` is set when the row is created, including on failed runs (previously `null` on failure).
- `LogAiResponse::handle()` now takes a `PendingStep` and returns the `StepResult`. Code that called it directly must stop doing so.
- `PendingAiResponseLogs` is keyed by invocation id instead of agent instance.
- Logging failures are caught and reported instead of propagating into the AI call.

### `TraceAiResponse` Is Deprecated

**Likelihood Of Impact: Low**

Failed runs are now traced automatically by `ExportTrace` from the `AgentFailed` event, so `TraceAiResponse` is a no-op. Remove it from your agents' `middleware()` before 6.0. Failures now ship one error span per failed run (with its failovers in `metadata.failovers`) instead of one per failed provider attempt.

# Concurrent offline evals — design

## Problem

`ai:eval` (`RunEvalCommand::handle()`) runs dataset rows strictly sequentially via Laravel's `progress()` helper, one `evaluateRow()` call at a time. Each row replays the agent (an LLM call), scores it (including JS scorers that shell out to Node), and this is slow in aggregate for datasets with many rows/trials. There's no reason rows can't run in parallel — each row is already fully isolated (own DB transaction, own throwaway agent boot, own Node subprocess for JS scorers).

## Approach

Use Laravel's `Concurrency` facade (process driver) to fork one OS process per row, batched by a `--concurrency=` option (default 5). Chunk the flattened `$runs` list into batches of that size; for each batch, call `Concurrency::run()` with one closure per row, wait for the batch, merge results, then move to the next batch.

This was chosen over in-process async (fibers) because:
- Each row already does `DB::beginTransaction()`/`rollBack()` — a shared connection can't have overlapping transactions from concurrent fibers, but separate forked processes each get their own connection.
- The JS scorer already blocks via `Process::run()` shelling out to Node — no fiber rework needed since forked processes block independently.
- No restructuring of `evaluateRow()`'s internals is needed, only its interface with the caller.

## Changes

### `RunEvalCommand`

- New `--concurrency=` option (positive int, default 5).
- `handle()` chunks `$runs` into batches of that size (`array_chunk`) and calls `Concurrency::run($closures)` per batch instead of `progress(...)`.
- `evaluateRow(array $row, ...)` return type changes from `?ExperimentEventData` to a plain result array: `['event' => ?ExperimentEventData, 'failure' => ?array]`. It no longer appends to `$this->failures` directly — the caller merges `failure` entries from each batch's results into `$this->failures` after the batch completes (mutation happens in the parent process, where `$this` actually persists).
- Progress reporting: since forked closures can't tick the parent's progress bar, replace `progress()` with output written after each batch completes (e.g. `$this->info("Scored {$done}/{$total}")`), driven by the parent loop over batches — no per-row live progress bar.
- Everything a forked closure closes over (row data, target key, provider/model overrides, dataset options) must be plain serializable values, not live objects (DB connections, service container bindings, agent instances) — `evaluateRow()` already reconstructs its own environment per row from primitives, so closures pass the row array and scalar options only.

### Ordering

Result order across batches is preserved (batch N always completes before batch N+1 starts), but *within* a batch, completion order across forked processes is not guaranteed. Since downstream consumers (NDJSON output, Braintrust experiment push) don't currently depend on row order, this is acceptable. If exact row order in output matters later, batches can be re-sorted by original index — out of scope here since no current consumer needs it.

### Testing

- Existing `RunEvalCommandTest.php` sequential-behavior assertions keep passing with default settings (small test datasets fit in one batch of 5, so ordering is unaffected for existing tests).
- New test: `--concurrency=` is honored (e.g. assert `Concurrency::run()` is invoked with the expected batch sizes, using `Concurrency::fake()` if available, or asserting batch counts via a testable seam).
- New test: failures from multiple concurrent rows are all captured in `$this->failures` (not just the last one), simulating multiple rows failing within the same batch.

## Out of scope

- Concurrency for the JS scorer's Node subprocess pool (already per-call isolated, not the bottleneck being addressed).
- Reordering NDJSON/experiment output to match original row order.
- Concurrency for `ai:scaffold-eval` or `ai:publish-eval` — this only touches offline `ai:eval`.

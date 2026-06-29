<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

interface EvalHarness
{
    /**
     * Set up a throwaway world for one dataset row and return the environment the
     * target builds its agent from (e.g. a session, brand, catalogue). The
     * command wraps this in a transaction and rolls it back, so a row leaves no
     * trace.
     *
     * @param  array<string, mixed>  $row
     */
    public function boot(array $row): EvalEnvironment;

    /**
     * Scorer context threaded into the eval subject for this environment (e.g.
     * the block catalogue and brand a scorer needs to reason about the output),
     * or null when scorers need no domain context.
     */
    public function context(EvalEnvironment $environment): ?object;

    /**
     * Experiment-level metadata recorded against the whole run (e.g. a catalogue
     * id snapshot so runs are comparable). Return an empty array when there is
     * nothing to record.
     *
     * @return array<string, mixed>
     */
    public function experimentMetadata(): array;
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

use Laravel\Ai\Contracts\Agent;

interface EvalTarget extends DatasetTarget
{
    /**
     * The text sent to the agent under test for a dataset row.
     *
     * @param  array<string, mixed>  $row
     */
    public function promptInput(array $row): string;

    /**
     * Build the agent under test for the environment the harness booted. The
     * environment is opaque to the package — the target casts it to the type its
     * harness returns. The dataset row is passed so a target can seed agent
     * context from it (e.g. a chat router needs an email state to route against).
     *
     * @param  array<string, mixed>  $row
     */
    public function agent(EvalEnvironment $environment, array $row = []): Agent;

    /**
     * Per-row context threaded into the eval subject's input (e.g. the outliner's
     * expected element set). Most targets need nothing.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function subjectInput(array $row): array;
}

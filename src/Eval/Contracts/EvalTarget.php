<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

use Laravel\Ai\Contracts\Agent;

interface EvalTarget
{
    /**
     * Stable key — used for the target argument, the experiment-name prefix, and
     * the interactive picker value.
     */
    public function key(): string;

    /**
     * Human label shown in the interactive picker and run banner.
     */
    public function label(): string;

    /**
     * Default dataset path (relative to the app base path) when --dataset is not
     * given.
     */
    public function defaultDataset(): string;

    /**
     * The text sent to the agent under test for a dataset row.
     *
     * @param  array<string, mixed>  $row
     */
    public function promptInput(array $row): string;

    /**
     * The scorers that define "good" for this agent.
     *
     * @return array<int, Scorer>
     */
    public function scorers(): array;

    /**
     * Build the agent under test for the environment the harness booted. The
     * environment is opaque to the package — the target casts it to the type its
     * harness returns. The dataset row is passed so a target can seed agent
     * context from it (e.g. a chat router needs an email state to route against).
     *
     * @param  array<string, mixed>  $row
     */
    public function agent(object $environment, array $row = []): Agent;

    /**
     * Per-row context threaded into the eval subject's input (e.g. the outliner's
     * expected element set). Most targets need nothing.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function subjectInput(array $row): array;
}

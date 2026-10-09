<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

/**
 * What every eval target shares, whether it runs an agent ({@see EvalTarget})
 * or a classification ({@see ClassifierEvalTarget}) over the dataset.
 */
interface DatasetTarget
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
     * Default dataset when --dataset is not given: a path relative to the app
     * base path, or `braintrust:<name>` for a dataset in the Braintrust project.
     */
    public function defaultDataset(): string;

    /**
     * The scorers that define "good" for this target.
     *
     * @return array<int, Scorer>
     */
    public function scorers(): array;
}

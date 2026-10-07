<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

interface FiltersDatasetRows
{
    /**
     * Whether a dataset row should be evaluated. Implement on an EvalTarget that
     * must ignore some rows (e.g. drafts with no expected answer yet); the runner
     * applies it right after loading the dataset, before --tag and --limit, and
     * reports how many rows were skipped. Targets that run every row omit this.
     *
     * @param  array<string, mixed>  $row
     */
    public function includeRow(array $row): bool;
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

use AgentSoftware\LaravelAiCompanion\Eval\ClassificationCase;

/**
 * An eval target for a classification (`Laravel\Ai\Classification`) rather
 * than an agent. The runner classifies each row's case with the provider and
 * model from --provider/--model, scores the answers, and exports them like an
 * agent run. No harness is needed for a classification.
 */
interface ClassifierEvalTarget extends DatasetTarget
{
    /**
     * What to classify for a dataset row — the state, the keyed questions, any
     * attachments — and the answers the row expects. One target can serve many
     * decisions by mapping a row field (e.g. `decision`) to its questions.
     *
     * @param  array<string, mixed>  $row
     */
    public function classification(array $row): ClassificationCase;
}

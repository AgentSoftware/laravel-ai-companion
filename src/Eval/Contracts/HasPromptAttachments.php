<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

interface HasPromptAttachments
{
    /**
     * Attachments (e.g. images) sent to the agent alongside the text prompt for a
     * dataset row. Implement on an EvalTarget whose agent is multimodal; the runner
     * passes the result as the prompt's attachments. Text-only targets omit this.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, mixed>
     */
    public function promptAttachments(array $row): array;
}

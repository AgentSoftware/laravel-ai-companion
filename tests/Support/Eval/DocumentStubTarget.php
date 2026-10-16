<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\HasPromptAttachments;

class DocumentStubTarget extends TextStubTarget implements HasPromptAttachments
{
    public function key(): string
    {
        return 'stub-document';
    }

    public function promptAttachments(array $row): array
    {
        return [$row['document']];
    }
}

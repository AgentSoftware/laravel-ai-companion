<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\HasPromptAttachments;
use Laravel\Ai\Files\RemoteImage;

class AttachmentStubTarget extends TextStubTarget implements HasPromptAttachments
{
    public function key(): string
    {
        return 'stub-attach';
    }

    public function promptAttachments(array $row): array
    {
        return [new RemoteImage('https://example.test/photo.jpg')];
    }
}

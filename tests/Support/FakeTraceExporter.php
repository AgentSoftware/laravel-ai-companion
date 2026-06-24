<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support;

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;

class FakeTraceExporter implements TraceExporter
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $batches = [];

    public bool $isEnabled = true;

    public function enabled(): bool
    {
        return $this->isEnabled;
    }

    public function ship(array $spans): void
    {
        $this->batches[] = $spans;
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Contracts;

interface HasLoggableProperties
{
    /** @return array<string, mixed> */
    public function loggableProperties(): array;
}

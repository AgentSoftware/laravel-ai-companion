<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding;

use AgentSoftware\LaravelAiCompanion\Tests\Support\StubAgent;
use DateTimeInterface;

final class ObjectParamFixtureAgent extends StubAgent
{
    public function __construct(
        public string $companyBrandTone,
        public int $maxPages = 5,
        public ?DateTimeInterface $clock = null,
    ) {}
}

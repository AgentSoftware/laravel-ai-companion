<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

final readonly class BraintrustLogsSource implements DatasetSource
{
    public function __construct(
        private BraintrustApi $api,
        private ?string $agentName = null,
    ) {}

    public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array
    {
        return collect($this->api->logEvents($limit, $this->agentName))
            ->map(fn (array $event): array => BraintrustApi::toRow($event, $includeExpected, $includeMetadata))
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

final readonly class BraintrustDatasetSource implements DatasetSource
{
    public function __construct(
        private BraintrustApi $api,
        private string $datasetId,
    ) {}

    public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array
    {
        return collect($this->api->datasetEvents($this->datasetId, $limit))
            ->map(fn (array $event): array => BraintrustApi::toRow($event, $includeExpected, $includeMetadata))
            ->values()
            ->all();
    }
}

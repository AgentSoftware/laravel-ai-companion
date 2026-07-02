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
        return array_map(
            fn (array $event): array => BraintrustApi::toRow($event, $includeExpected, $includeMetadata),
            $this->api->datasetEvents($this->datasetId, $limit),
        );
    }
}

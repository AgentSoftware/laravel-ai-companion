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
        $events = array_filter(
            $this->api->logEvents($limit),
            fn (array $event): bool => $this->matchesAgent($event),
        );

        return array_values(array_map(
            fn (array $event): array => BraintrustApi::toRow($event, $includeExpected, $includeMetadata),
            $events,
        ));
    }

    /** @param  array<string, mixed>  $event */
    private function matchesAgent(array $event): bool
    {
        if ($this->agentName === null) {
            return true;
        }

        $name = $event['span_attributes']['name'] ?? $event['metadata']['agent'] ?? null;

        return is_string($name) && str_contains($name, $this->agentName);
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

interface DatasetSource
{
    /**
     * Fetch historical interactions as eval dataset rows. Each row is
     * `{"prompt": string, "expected"?: mixed, ...flattened scalar metadata}`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array;
}

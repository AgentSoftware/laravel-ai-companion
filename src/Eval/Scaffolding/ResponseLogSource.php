<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Illuminate\Database\Eloquent\Builder;

final readonly class ResponseLogSource implements DatasetSource
{
    public function __construct(private ?string $agentClass = null) {}

    public function fetch(int $limit, bool $includeExpected, bool $includeMetadata): array
    {
        return AiResponseLog::query()
            ->when($this->agentClass !== null, fn (Builder $query) => $query->where('agent', $this->agentClass))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (AiResponseLog $log) use ($includeExpected, $includeMetadata): array {
                $row = ['prompt' => $log->prompt];

                if ($includeExpected && $log->response !== null) {
                    $row['expected'] = $log->response;
                }

                if ($includeMetadata) {
                    $row += array_filter(
                        [...($log->metadata ?? []), ...($log->properties ?? [])],
                        fn (mixed $value): bool => is_scalar($value),
                    );
                }

                return $row;
            })
            ->values()
            ->all();
    }
}

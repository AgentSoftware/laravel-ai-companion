<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion;

/**
 * Correlates an in-flight AiResponseLog row to its invocation id.
 *
 * Tool-call and completion events fire per run and need to resolve the log
 * row without a database lookup per event — and without one at all for the
 * agents that never opted in, since only runs RecordAiResponseLog started a
 * row for are registered here.
 */
class PendingAiResponseLogs
{
    private const int MAX_ENTRIES = 500;

    /** @var array<string, string> invocation id => AiResponseLog id */
    private array $logIds = [];

    public function put(string $invocationId, string $logId): void
    {
        if (count($this->logIds) >= self::MAX_ENTRIES) {
            array_shift($this->logIds);
        }

        $this->logIds[$invocationId] = $logId;
    }

    public function get(string $invocationId): ?string
    {
        return $this->logIds[$invocationId] ?? null;
    }

    public function forget(string $invocationId): void
    {
        unset($this->logIds[$invocationId]);
    }
}

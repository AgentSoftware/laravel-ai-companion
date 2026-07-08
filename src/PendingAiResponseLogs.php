<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion;

/**
 * Correlates an in-flight AiResponseLog row to its Agent instance.
 *
 * AiResponseLog::invocation_id is only known once the whole agent run
 * completes, but tool-call events fire mid-run and need to resolve the
 * log row immediately. Keying by the Agent instance (unique per request)
 * lets LogAiResponse register the row as soon as it's created, without
 * relying on the event bus or leaking listeners across concurrent runs.
 */
class PendingAiResponseLogs
{
    private const int MAX_ENTRIES = 500;

    /** @var array<int, string> spl_object_id(Agent) => AiResponseLog id */
    private array $logIds = [];

    public function put(object $agent, string $logId): void
    {
        if (count($this->logIds) >= self::MAX_ENTRIES) {
            array_shift($this->logIds);
        }

        $this->logIds[spl_object_id($agent)] = $logId;
    }

    public function get(object $agent): ?string
    {
        return $this->logIds[spl_object_id($agent)] ?? null;
    }

    public function forget(object $agent): void
    {
        unset($this->logIds[spl_object_id($agent)]);
    }
}

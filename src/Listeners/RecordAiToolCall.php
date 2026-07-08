<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Listeners;

use AgentSoftware\LaravelAiCompanion\Models\AiToolCall;
use AgentSoftware\LaravelAiCompanion\PendingAiResponseLogs;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

readonly class RecordAiToolCall
{
    public function __construct(
        private TraceTimings $timings,
        private PendingAiResponseLogs $pending,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            InvokingTool::class => 'handleInvokingTool',
            ToolInvoked::class => 'handleToolInvoked',
        ];
    }

    public function handleInvokingTool(InvokingTool $event): void
    {
        rescue(fn () => $this->timings->start("tool_call:{$event->toolInvocationId}", microtime(true)), null, false);
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        rescue(function () use ($event): void {
            $startedAt = $this->timings->pull("tool_call:{$event->toolInvocationId}");

            $logId = $this->pending->get($event->agent);

            if ($logId === null) {
                return;
            }

            $durationMs = $startedAt !== null
                ? (int) round((microtime(true) - $startedAt) * 1000)
                : null;

            AiToolCall::create([
                'ai_response_log_id' => $logId,
                'tool_invocation_id' => $event->toolInvocationId,
                'tool' => $event->tool::class,
                'input' => $event->arguments,
                'output' => $event->result,
                'duration_ms' => $durationMs,
            ]);
        });
    }
}

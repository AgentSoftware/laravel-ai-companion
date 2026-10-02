<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Listeners;

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Middleware\LogAiResponse;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\PendingAiResponseLogs;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Writes one ai_response_logs row per run for agents that opted in with the
 * LogAiResponse middleware: a Running row when the run starts, completed by
 * AgentPrompted or AgentFailed.
 */
readonly class RecordAiResponseLog
{
    public function __construct(
        private PendingAiResponseLogs $pending,
        private TraceTimings $timings,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            PromptingAgent::class => 'handlePromptingAgent',
            AgentPrompted::class => 'handleAgentPrompted',
            AgentFailed::class => 'handleAgentFailed',
        ];
    }

    public function handlePromptingAgent(PromptingAgent $event): void
    {
        rescue(function () use ($event): void {
            // PromptingAgent fires once per provider attempt under failover, all
            // sharing one invocation id, so only the first attempt starts a row.
            if ($this->pending->get($event->invocationId) !== null) {
                return;
            }

            $agent = $event->prompt->agent;

            if (! LogAiResponse::appliesTo($agent)) {
                return;
            }

            $log = AiResponseLog::create([
                'invocation_id' => $event->invocationId,
                'agent' => $agent::class,
                'prompt' => $event->prompt->prompt,
                'properties' => $agent instanceof HasLoggableProperties
                    ? $agent->loggableProperties()
                    : null,
                'status' => AiResponseStatus::Running,
            ]);

            $this->pending->put($event->invocationId, $log->id);
            $this->timings->start("response_log:{$event->invocationId}", microtime(true));
        });
    }

    public function handleAgentPrompted(AgentPrompted $event): void
    {
        rescue(function () use ($event): void {
            $logId = $this->pending->get($event->invocationId);

            if ($logId === null) {
                return;
            }

            $this->pending->forget($event->invocationId);

            AiResponseLog::find($logId)?->update([
                'feedback_span_id' => $this->feedbackSpanId($event->invocationId),
                'response' => $event->response instanceof StructuredAgentResponse
                    ? $event->response->toArray()
                    : ['text' => $event->response->text],
                'metadata' => $event->response->meta->toArray(),
                'status' => AiResponseStatus::Success,
                'duration_ms' => $this->durationMs($event->invocationId),
            ]);
        });
    }

    public function handleAgentFailed(AgentFailed $event): void
    {
        rescue(function () use ($event): void {
            $logId = $this->pending->get($event->invocationId);

            if ($logId === null) {
                return;
            }

            $this->pending->forget($event->invocationId);

            AiResponseLog::find($logId)?->update([
                'status' => AiResponseStatus::Failure,
                'duration_ms' => $this->durationMs($event->invocationId),
            ]);
        });
    }

    private function durationMs(string $invocationId): ?int
    {
        $startedAt = $this->timings->pull("response_log:{$invocationId}");

        return $startedAt !== null ? (int) ((microtime(true) - $startedAt) * 1000) : null;
    }

    /**
     * The Braintrust span a user's feedback should attach to: the deterministic
     * source-keyed root span when the flow set a Context source, otherwise the
     * invocation span (the only span shipped for a source-less run). Mirrors the
     * grouping SpanBuilder uses when exporting the trace.
     *
     * When a source is set this id is flow-level, not per-response: every log
     * row in the same business flow shares the one root span id, so feedback
     * recorded per row lands on the same span (last write wins).
     */
    private function feedbackSpanId(string $invocationId): string
    {
        $sourceModel = Context::get('ai_usage_source_model');
        $sourceId = Context::get('ai_usage_source_id');

        if (filled($sourceModel) && filled($sourceId)) {
            return SpanBuilder::rootSpanId((string) $sourceModel, (string) $sourceId);
        }

        return $invocationId;
    }
}

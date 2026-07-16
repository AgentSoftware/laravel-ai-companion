<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Middleware;

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\PendingAiResponseLogs;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class LogAiResponse
{
    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        $agent = $prompt->agent;

        $log = AiResponseLog::create([
            'agent' => $agent::class,
            'prompt' => $prompt->prompt,
            'properties' => $agent instanceof HasLoggableProperties
                ? $agent->loggableProperties()
                : null,
            'status' => AiResponseStatus::Running,
        ]);

        // AgentResponse only carries the invocation ID once the whole run
        // finishes, but tool-call events fire mid-run. Register this row
        // against the Agent instance now so RecordAiToolCall can resolve it
        // before the run completes. Resolved from the container (not
        // constructor-injected) because agents construct this middleware
        // directly with `new LogAiResponse`.
        $pending = Container::getInstance()->make(PendingAiResponseLogs::class);

        $pending->put($agent, $log->id);

        $startedAt = microtime(true);

        try {
            /** @var AgentResponse $response */
            $response = $next($prompt);
        } catch (Throwable $e) {
            $pending->forget($agent);

            $log->update([
                'status' => AiResponseStatus::Failure,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        return $response->then(function (AgentResponse $response) use ($agent, $log, $startedAt, $pending): void {
            $pending->forget($agent);

            $log->update([
                'invocation_id' => $response->invocationId,
                'feedback_span_id' => $this->feedbackSpanId($response),
                'response' => $response instanceof StructuredAgentResponse
                    ? $response->toArray()
                    : ['text' => $response->text],
                'metadata' => $response->meta->toArray(),
                'status' => AiResponseStatus::Success,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        });
    }

    /**
     * The Braintrust span a user's feedback should attach to: the deterministic
     * source-keyed root span when the flow set a Context source, otherwise the
     * invocation span (the only span shipped for a source-less run). Mirrors the
     * grouping SpanBuilder uses when exporting the trace.
     */
    private function feedbackSpanId(AgentResponse $response): string
    {
        $sourceModel = Context::get('ai_usage_source_model');
        $sourceId = Context::get('ai_usage_source_id');

        if (filled($sourceModel) && filled($sourceId)) {
            return SpanBuilder::rootSpanId((string) $sourceModel, (string) $sourceId);
        }

        return $response->invocationId;
    }
}

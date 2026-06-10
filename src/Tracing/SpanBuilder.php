<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Ramsey\Uuid\Uuid;

class SpanBuilder
{
    /**
     * Build the span for a completed agent invocation.
     *
     * @param  array<int, array<string, mixed>>  $failovers
     * @return array<string, mixed>
     */
    public function agentSpan(AgentPrompted $event, ?float $startedAt, float $endedAt, array $failovers = []): array
    {
        $rootId = $this->rootId();
        $usage = $event->response->usage;

        return [
            'id' => $event->invocationId,
            'trace_id' => $rootId ?? $event->invocationId,
            'parent_id' => $rootId,
            'name' => class_basename($event->prompt->agent),
            'type' => 'llm',
            'input' => [
                'prompt' => $event->prompt->prompt,
                'instructions' => rescue(fn (): string => $event->prompt->agent->instructions(), null, false),
            ],
            'output' => $event->response instanceof StructuredAgentResponse
                ? $event->response->toArray()
                : $event->response->text,
            'error' => null,
            'metadata' => array_merge($this->baseMetadata(), array_filter([
                'agent' => $event->prompt->agent::class,
                'model' => $event->response->meta->model ?? $event->prompt->model,
                'provider' => $event->response->meta->provider,
                'failovers' => $failovers !== [] ? $failovers : null,
            ])),
            'metrics' => [
                'start' => $startedAt,
                'end' => $endedAt,
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'tokens' => $usage->promptTokens + $usage->completionTokens,
                'cache_write_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
            ],
        ];
    }

    /**
     * Build the span for a completed tool invocation.
     *
     * @return array<string, mixed>
     */
    public function toolSpan(ToolInvoked $event, ?float $startedAt, float $endedAt): array
    {
        return [
            'id' => $event->toolInvocationId,
            'trace_id' => $this->rootId() ?? $event->invocationId,
            'parent_id' => $event->invocationId,
            'name' => class_basename($event->tool),
            'type' => 'tool',
            'input' => $event->arguments,
            'output' => $event->result,
            'error' => null,
            'metadata' => array_merge($this->baseMetadata(), [
                'agent' => $event->agent::class,
                'tool' => $event->tool::class,
            ]),
            'metrics' => [
                'start' => $startedAt,
                'end' => $endedAt,
            ],
        ];
    }

    /**
     * Build the trace root span for the current business source, if any.
     *
     * Deterministic id means every listener can upsert it; the backend
     * merges events that share an id.
     *
     * @return array<string, mixed>|null
     */
    public function rootSpan(): ?array
    {
        $rootId = $this->rootId();

        if ($rootId === null) {
            return null;
        }

        return [
            'id' => $rootId,
            'trace_id' => $rootId,
            'parent_id' => null,
            'name' => class_basename((string) Context::get('ai_usage_source_model')),
            'type' => 'task',
            'input' => null,
            'output' => null,
            'error' => null,
            'metadata' => $this->baseMetadata(),
            'metrics' => [],
        ];
    }

    private function rootId(): ?string
    {
        $sourceId = Context::get('ai_usage_source_id');
        $sourceModel = Context::get('ai_usage_source_model');

        if (blank($sourceId) || blank($sourceModel)) {
            return null;
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, "ai-companion:{$sourceModel}:{$sourceId}")->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseMetadata(): array
    {
        return array_filter([
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'source_model' => Context::get('ai_usage_source_model'),
            'source_id' => Context::get('ai_usage_source_id'),
        ]);
    }
}

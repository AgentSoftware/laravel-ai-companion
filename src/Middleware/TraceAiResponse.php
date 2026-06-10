<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Middleware;

use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

readonly class TraceAiResponse
{
    public function __construct(
        private SpanBuilder $builder,
        private TraceTimings $timings,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        $startedAt = microtime(true);

        // Runs inside the SDK's failover loop, so this fires per failed provider
        // attempt: a recovered failover ships one error span for the failed
        // attempt plus the eventual success span from the ExportTrace listener.
        try {
            return $next($prompt);
        } catch (Throwable $exception) {
            rescue(function () use ($prompt, $exception, $startedAt): void {
                $this->shipErrorSpan($prompt, $exception, $startedAt);
            });

            throw $exception;
        }
    }

    private function shipErrorSpan(AgentPrompt $prompt, Throwable $exception, float $startedAt): void
    {
        // PromptingAgent already recorded a start entry, but AgentPrompted never
        // fires on this failure path. The SDK passes a null invocationId into the
        // middleware prompt on the standard path, so this pull is best-effort —
        // TraceTimings caps its entries to bound any unconsumed leftovers.
        if ($prompt->invocationId !== null) {
            $startedAt = $this->timings->pull("agent:{$prompt->invocationId}") ?? $startedAt;
        }

        $root = $this->builder->rootSpan();
        $id = $prompt->invocationId ?? (string) Str::uuid();

        $span = [
            'id' => $id,
            'trace_id' => $root['id'] ?? $id,
            'parent_id' => $root['id'] ?? null,
            'name' => class_basename($prompt->agent),
            'type' => 'llm',
            'input' => ['prompt' => $prompt->prompt],
            'output' => null,
            'error' => $exception->getMessage(),
            'metadata' => [
                'agent' => $prompt->agent::class,
                'model' => $prompt->model,
                'exception' => $exception::class,
            ],
            'metrics' => [
                'start' => $startedAt,
                'end' => microtime(true),
            ],
        ];

        $spans = array_values(array_filter([$root, $span]));

        // Serialization guard: spans must survive the queue as plain data.
        json_encode($spans, JSON_THROW_ON_ERROR);

        ShipSpans::dispatch($spans);
    }
}

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
        // The ExportTrace listener never sees a hard failure (no AgentPrompted
        // event fires), so consume the orphaned start entry here to avoid
        // leaking it in the long-lived singleton — and prefer its earlier time.
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

        ShipSpans::dispatch(array_values(array_filter([$root, $span])));
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Listeners;

use AgentSoftware\LaravelAiCompanion\Tracing\Jobs\ShipSpans;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\StreamedAgentResponse;

readonly class ExportTrace
{
    public function __construct(
        private TraceTimings $timings,
        private SpanBuilder $builder,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            PromptingAgent::class => 'handlePromptingAgent',
            AgentPrompted::class => 'handleAgentPrompted',
            InvokingTool::class => 'handleInvokingTool',
            ToolInvoked::class => 'handleToolInvoked',
            AgentFailedOver::class => 'handleAgentFailedOver',
        ];
    }

    public function handlePromptingAgent(PromptingAgent $event): void
    {
        rescue(fn () => $this->timings->start("agent:{$event->invocationId}", microtime(true)), null, false);
    }

    public function handleAgentPrompted(AgentPrompted $event): void
    {
        rescue(function () use ($event): void {
            // Pull timings and failovers before the streamed guard so entries are
            // always consumed and never leak in the long-lived singleton.
            $startedAt = $this->timings->pull("agent:{$event->invocationId}");
            $failovers = $this->timings->pullFailovers($event->prompt->agent::class);

            if ($event->response instanceof StreamedAgentResponse) {
                return;
            }

            $this->ship($this->builder->agentSpan($event, $startedAt, microtime(true), $failovers));
        });
    }

    public function handleInvokingTool(InvokingTool $event): void
    {
        rescue(fn () => $this->timings->start("tool:{$event->toolInvocationId}", microtime(true)), null, false);
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        rescue(function () use ($event): void {
            $this->ship($this->builder->toolSpan(
                $event,
                $this->timings->pull("tool:{$event->toolInvocationId}"),
                microtime(true),
            ));
        });
    }

    public function handleAgentFailedOver(AgentFailedOver $event): void
    {
        rescue(function () use ($event): void {
            $error = $event->exception instanceof \Throwable
                ? $event->exception->getMessage()
                : '';

            // AgentFailedOver carries no invocation id, so failovers are parked by
            // agent class and attached to that class's next completed prompt. This
            // assumes synchronous, non-interleaved invocations within one process.
            $this->timings->addFailover($event->agent::class, [
                'provider' => class_basename($event->provider),
                'model' => $event->model,
                'error' => $error,
            ]);
        }, null, false);
    }

    /**
     * @param  array<string, mixed>  $span
     */
    private function ship(array $span): void
    {
        $spans = array_values(array_filter([$this->builder->rootSpan(), $span]));

        // Serialization guard: spans must survive the queue as plain data.
        json_encode($spans, JSON_THROW_ON_ERROR);

        ShipSpans::dispatch($spans);
    }
}

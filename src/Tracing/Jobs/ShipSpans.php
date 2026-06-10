<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing\Jobs;

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShipSpans implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<int, array<string, mixed>>  $spans
     */
    public function __construct(public array $spans)
    {
        $this->onConnection(config('ai-companion.braintrust.queue.connection'));
        $this->onQueue(config('ai-companion.braintrust.queue.queue'));
    }

    public function handle(TraceExporter $exporter): void
    {
        if (! $exporter->enabled()) {
            return;
        }

        $exporter->ship($this->spans);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('AI trace spans could not be shipped and were dropped.', [
            'spans' => count($this->spans),
            'exception' => $exception->getMessage(),
        ]);
    }
}

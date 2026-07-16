<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Feedback;

use AgentSoftware\LaravelAiCompanion\Braintrust\InteractsWithBraintrustApi;
use AgentSoftware\LaravelAiCompanion\Exceptions\BraintrustFeedbackException;
use AgentSoftware\LaravelAiCompanion\Tracing\Exporters\BraintrustExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Records a user's thumbs up/down against the root span already shipped to
 * Braintrust for a business flow, keyed by the same $sourceModel/$sourceId
 * pair the app sets via Context for tracing (see SpanBuilder::rootSpanId).
 * A synchronous, foreground action — never queued, never silently swallowed.
 */
class BraintrustFeedbackClient
{
    use InteractsWithBraintrustApi;

    public function record(string $sourceModel, string $sourceId, bool $good, ?string $comment = null): void
    {
        $this->submit(SpanBuilder::rootSpanId($sourceModel, $sourceId), $good, $comment);
    }

    /**
     * Record feedback against a span by its Braintrust id directly — for flows
     * that have no deterministic source-keyed root span (the span was shipped
     * with its own invocation id as the span id).
     */
    public function recordForSpan(string $spanId, bool $good, ?string $comment = null): void
    {
        $this->submit($spanId, $good, $comment);
    }

    private function submit(string $spanId, bool $good, ?string $comment): void
    {
        if (! app(BraintrustExporter::class)->enabled()) {
            throw new BraintrustFeedbackException(
                'Cannot record Braintrust feedback: tracing is not enabled or no API key is configured.',
            );
        }

        $feedback = array_filter([
            'id' => $spanId,
            'scores' => ['user_feedback' => $good ? 1.0 : 0.0],
            'comment' => $comment,
            'source' => 'app',
        ], fn (mixed $value): bool => $value !== null);

        try {
            $this->request(fn () => $this->client()
                ->post("/v1/project_logs/{$this->projectId()}/feedback", ['feedback' => [$feedback]]));
        } catch (RuntimeException|RequestException $exception) {
            throw new BraintrustFeedbackException(
                "Braintrust feedback request failed: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}

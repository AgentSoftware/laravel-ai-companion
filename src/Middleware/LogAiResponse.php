<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Middleware;

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Closure;
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
            'instructions' => (string) $agent->instructions() ?: null,
            'prompt' => $prompt->prompt,
            'properties' => $agent instanceof HasLoggableProperties
                ? $agent->loggableProperties()
                : null,
            'status' => AiResponseStatus::Running,
        ]);

        $startedAt = microtime(true);

        try {
            /** @var AgentResponse $response */
            $response = $next($prompt);
        } catch (Throwable $e) {
            $log->update([
                'status' => AiResponseStatus::Failure,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        return $response->then(function (AgentResponse $response) use ($log, $startedAt): void {
            $log->update([
                'invocation_id' => $response->invocationId,
                'response' => $response instanceof StructuredAgentResponse
                    ? $response->toArray()
                    : ['text' => $response->text],
                'metadata' => $response->meta->toArray(),
                'status' => AiResponseStatus::Success,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        });
    }
}

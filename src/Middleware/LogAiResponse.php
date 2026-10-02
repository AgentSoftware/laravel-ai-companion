<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Middleware;

use Closure;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

/**
 * Opts an agent into response logging.
 *
 * Agent middleware wraps each generation step, and a step carries neither the
 * agent nor the original prompt, so the logging itself lives in the
 * RecordAiResponseLog subscriber, which writes one row per run from the agent
 * events. This middleware is only the per-agent opt-in marker.
 */
class LogAiResponse
{
    /**
     * Determine whether the given agent opted into response logging.
     */
    public static function appliesTo(Agent $agent): bool
    {
        if (! $agent instanceof HasMiddleware) {
            return false;
        }

        foreach ($agent->middleware() as $middleware) {
            if ($middleware instanceof self || $middleware === self::class) {
                return true;
            }
        }

        return false;
    }

    public function handle(PendingStep $step, Closure $next): StepResult
    {
        return $next($step);
    }
}

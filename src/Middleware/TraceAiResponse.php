<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Middleware;

use Closure;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

/**
 * @deprecated Failed runs are now traced automatically from the AgentFailed
 *             event by the ExportTrace subscriber. This middleware does
 *             nothing and will be removed in the next major version.
 */
readonly class TraceAiResponse
{
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        return $next($step);
    }
}

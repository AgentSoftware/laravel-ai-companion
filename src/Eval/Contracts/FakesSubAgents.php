<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

use Closure;

interface FakesSubAgents
{
    /**
     * Fakes for the sub-agents the agent-under-test delegates to, so a routing eval
     * can measure the router's tool choices without running real downstream LLM
     * calls. Keyed by sub-agent class. Unlike a canned `Agent::fake()`, each closure
     * receives the LIVE sub-agent instance (its typed constructor inputs) and the
     * prompt it was invoked with, so it can return structured output reflecting what
     * was actually requested — simulating a working sub-agent rather than a fixed
     * placeholder. Implement on an EvalTarget whose agent delegates; targets that
     * don't omit it.
     *
     * @param  array<string, mixed>  $row
     * @return array<class-string, Closure> fn(\Laravel\Ai\Contracts\Agent $agent, string $prompt): array
     */
    public function subAgentFakes(EvalEnvironment $environment, array $row): array;
}

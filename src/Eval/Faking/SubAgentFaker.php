<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Faking;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;

/**
 * Installs sub-agent fakes for a routing eval. Each fake is a closure that
 * receives the live sub-agent instance and returns its structured output, so the
 * fake can reflect what was actually requested (see FakesSubAgents). Bind as a
 * singleton so the event listeners are registered once and share one stack.
 */
final class SubAgentFaker
{
    private bool $listening = false;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly PromptingAgentStack $stack,
    ) {}

    /**
     * @param  array<class-string, Closure>  $fakes  fn(\Laravel\Ai\Contracts\Agent $agent): array
     */
    public function install(array $fakes): void
    {
        $this->listen();
        $this->stack->reset();

        foreach ($fakes as $class => $fake) {
            $class::fake(function () use ($class, $fake): array {
                $prompt = $this->stack->currentOfClass($class);

                return $prompt === null ? [] : $fake($prompt->agent, $prompt->prompt);
            });
        }
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->events->listen(PromptingAgent::class, function (PromptingAgent $event): void {
            $this->stack->push($event->prompt);
        });

        $this->events->listen(AgentPrompted::class, function (): void {
            $this->stack->pop();
        });

        $this->listening = true;
    }
}

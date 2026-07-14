<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Faking;

use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Tracks which agents are mid-prompt, so a sub-agent fake can recover the live
 * agent instance (and the prompt it was invoked with) it's standing in for.
 * laravel/ai's fake gateway only hands a closure the user-message string, so we
 * capture the whole AgentPrompt from the `PromptingAgent` event instead. A stack
 * (not a single slot) because an agent can prompt a sub-agent mid-run — the calls
 * bracket, so the top-most prompt for the faked class is the one running now.
 */
final class PromptingAgentStack
{
    /** @var array<int, AgentPrompt> */
    private array $prompts = [];

    public function push(AgentPrompt $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    public function pop(): void
    {
        array_pop($this->prompts);
    }

    public function reset(): void
    {
        $this->prompts = [];
    }

    /**
     * @param  class-string  $class
     */
    public function currentOfClass(string $class): ?AgentPrompt
    {
        for ($i = count($this->prompts) - 1; $i >= 0; $i--) {
            if ($this->prompts[$i]->agent instanceof $class) {
                return $this->prompts[$i];
            }
        }

        return null;
    }
}

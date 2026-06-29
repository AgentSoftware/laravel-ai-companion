<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

final readonly class EvalRunMetrics
{
    public function __construct(
        public int $latencyMs,
        public int $promptTokens,
        public int $completionTokens,
        public int $tokens,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'latency_ms' => $this->latencyMs,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'tokens' => $this->tokens,
        ];
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use Illuminate\Support\Str;

/**
 * Checks the agent actually invoked tools instead of answering conversationally.
 * Reads the invocation's tool names from input['tool_calls'] — populated by
 * RunEvalCommand offline and OnlineSpanScorer online — and scores 1.0 when at
 * least `min` calls match `pattern` (Str::is wildcard, e.g. 'Write*'; null
 * matches any tool).
 */
final readonly class ToolUsageScorer implements Scorer
{
    public function __construct(
        private string $name = 'used_tools',
        private ?string $pattern = null,
        private int $min = 1,
    ) {}

    public function score(EvalSubject $subject): Score
    {
        $calls = collect((array) ($subject->input['tool_calls'] ?? []))
            ->filter(fn (mixed $tool): bool => is_string($tool))
            ->filter(fn (string $tool): bool => $this->pattern === null || Str::is($this->pattern, $tool));

        return new Score($this->name, $calls->count() >= $this->min ? 1.0 : 0.0, [
            'matching_tool_calls' => $calls->count(),
            'pattern' => $this->pattern ?? '*',
            'min' => $this->min,
        ]);
    }
}

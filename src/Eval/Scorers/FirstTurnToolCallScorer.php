<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

/**
 * Fails a multi-step tool-using agent whose first completion called no tools
 * at all — the "I'll now do X" acknowledgement-instead-of-action failure mode,
 * where the model wastes a step describing what it will do rather than doing
 * it. Reads `first_step_tool_calls` from the subject input.
 */
final class FirstTurnToolCallScorer implements Scorer
{
    public function __construct(private string $name = 'first_turn_tool_call') {}

    public function score(EvalSubject $subject): Score
    {
        $called = $subject->input['first_step_tool_calls'] ?? [];

        return new Score($this->name, $called === [] ? 0.0 : 1.0, ['called' => $called]);
    }
}

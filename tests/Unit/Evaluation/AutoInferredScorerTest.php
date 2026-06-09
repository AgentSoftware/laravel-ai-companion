<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Evaluation\Scorers\AutoInferredScorer;

it('agent() returns the agent class name it was constructed with', function (): void {
    $scorer = new AutoInferredScorer('App\\Ai\\Agents\\ContentWriterAgent');

    expect($scorer->agent())->toBe('App\\Ai\\Agents\\ContentWriterAgent');
});

it('criteria() returns an empty array to trigger auto-inference', function (): void {
    $scorer = new AutoInferredScorer('App\\Ai\\Agents\\ContentWriterAgent');

    expect($scorer->criteria())->toBe([]);
});

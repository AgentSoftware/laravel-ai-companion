<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Judges\JudgeAgent;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\LlmJudgeScorer;
use Laravel\Ai\Gateway\TextGenerationOptions;

it('sends no temperature, which current Anthropic models reject', function (): void {
    expect(TextGenerationOptions::forAgent(JudgeAgent::make('rubric', 'reference', 10))->temperature)->toBeNull();
});

it('uses the configured judge model when no judge provider is configured', function (): void {
    config()->set('ai-companion.eval.judge.provider', null);
    config()->set('ai-companion.eval.judge.model', 'claude-haiku-5-5');

    $model = null;
    JudgeAgent::fake(function (string $prompt, mixed $attachments, mixed $provider, string $judgeModel) use (&$model): array {
        $model = $judgeModel;

        return ['rating' => 5, 'reasoning' => 'halfway'];
    });

    (new LlmJudgeScorer(name: 'faithful', rubric: 'rate it', input: 'brief', output: 'name'))->score(new EvalSubject(
        output: ['name' => 'Spring Sale Launch'],
        input: ['brief' => 'Announce the spring sale'],
    ));

    expect($model)->toBe('claude-haiku-5-5');
});

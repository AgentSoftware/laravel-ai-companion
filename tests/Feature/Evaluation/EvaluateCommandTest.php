<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Console\EvaluateCommand;
use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Evaluation\EvaluationRunner;
use AgentSoftware\LaravelAiCompanion\Evaluation\Judge\LlmJudge;
use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function seedCommandLog(array $overrides = []): AiResponseLog
{
    return AiResponseLog::create(array_merge([
        'agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
        'instructions' => 'Write content.',
        'prompt' => 'Write a hero.',
        'response' => ['text' => 'Welcome to Acme.'],
        'status' => AiResponseStatus::Success,
    ], $overrides));
}

function makeCommandJudgeResponse(int $score = 78): StructuredAgentResponse
{
    return new StructuredAgentResponse(
        invocationId: 'j1',
        structured: [
            'overall_score' => $score,
            'criteria' => [
                ['name' => 'accuracy', 'score' => 80, 'feedback' => 'Mostly accurate.'],
                ['name' => 'tone',     'score' => 75, 'feedback' => 'Professional but bland.'],
            ],
            'summary' => 'Reasonable quality with room to improve tone.',
        ],
        text: '{}',
        usage: new Usage(100, 200, 0, 0),
        meta: new Meta,
    );
}

it('evaluates the specified agent and writes a row to ai_evaluations', function (): void {
    seedCommandLog();

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')->once()->andReturn(makeCommandJudgeResponse());

    $runner = new EvaluationRunner(fn (string $criteria) => $fakeJudge);
    $this->app->instance(EvaluationRunner::class, $runner);

    $this->artisan(EvaluateCommand::class, [
        '--agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
    ])->assertSuccessful();

    expect(AiEvaluation::count())->toBe(1)
        ->and(AiEvaluation::first()->overall_score)->toBe(78);
});

it('skips already-evaluated logs unless --re-run is passed', function (): void {
    $log = seedCommandLog();
    AiEvaluation::create([
        'ai_response_log_id' => $log->id,
        'agent' => $log->agent,
        'overall_score' => 90,
        'criteria' => [],
        'summary' => 'Already evaluated.',
        'judge_model' => 'claude-haiku-4-5-20251001',
    ]);

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldNotReceive('prompt');
    $runner = new EvaluationRunner(fn (string $criteria) => $fakeJudge);
    $this->app->instance(EvaluationRunner::class, $runner);

    $this->artisan(EvaluateCommand::class, [
        '--agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
    ])->assertSuccessful();

    expect(AiEvaluation::count())->toBe(1);
});

it('re-evaluates already-evaluated logs when --re-run is passed', function (): void {
    $log = seedCommandLog();
    AiEvaluation::create([
        'ai_response_log_id' => $log->id,
        'agent' => $log->agent,
        'overall_score' => 90,
        'criteria' => [],
        'summary' => 'Old evaluation.',
        'judge_model' => 'claude-haiku-4-5-20251001',
    ]);

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')->once()->andReturn(makeCommandJudgeResponse(55));
    $runner = new EvaluationRunner(fn (string $criteria) => $fakeJudge);
    $this->app->instance(EvaluationRunner::class, $runner);

    $this->artisan(EvaluateCommand::class, [
        '--agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
        '--re-run' => true,
    ])->assertSuccessful();

    expect(AiEvaluation::count())->toBe(2);
});

it('returns success with no evaluation when no logs match', function (): void {
    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldNotReceive('prompt');
    $runner = new EvaluationRunner(fn (string $criteria) => $fakeJudge);
    $this->app->instance(EvaluationRunner::class, $runner);

    $this->artisan(EvaluateCommand::class, [
        '--agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
    ])->assertSuccessful();

    expect(AiEvaluation::count())->toBe(0);
});

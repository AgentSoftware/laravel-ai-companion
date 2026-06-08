<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Evaluation\EvaluationRunner;
use AgentSoftware\LaravelAiCompanion\Evaluation\Judge\LlmJudge;
use AgentSoftware\LaravelAiCompanion\Evaluation\Scorers\Scorer;
use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function makeResponseLog(array $overrides = []): AiResponseLog
{
    return AiResponseLog::create(array_merge([
        'agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
        'instructions' => 'You are a content writer for estate agents.',
        'prompt' => 'Write a homepage hero section for Acme Estates.',
        'response' => ['text' => 'Welcome to Acme Estates — your trusted local partner.'],
        'status' => AiResponseStatus::Success,
    ], $overrides));
}

function makeJudgeResponse(): StructuredAgentResponse
{
    return new StructuredAgentResponse(
        invocationId: 'judge-inv-1',
        structured: [
            'overall_score' => 82,
            'criteria' => [
                ['name' => 'accuracy',     'score' => 85, 'feedback' => 'Reflects company info correctly.'],
                ['name' => 'completeness', 'score' => 70, 'feedback' => 'Missing a CTA.'],
                ['name' => 'tone',         'score' => 90, 'feedback' => 'Professional and engaging.'],
            ],
            'summary' => 'Good quality overall. The CTA section is missing which reduces completeness.',
        ],
        text: '{}',
        usage: new Usage(100, 200, 0, 0),
        meta: new Meta(provider: 'anthropic', model: 'claude-haiku-4-5-20251001'),
    );
}

function makeRunner(?Closure $judgeFactory = null): EvaluationRunner
{
    return new EvaluationRunner($judgeFactory);
}

it('stores an evaluation result for a successful log', function (): void {
    $log = makeResponseLog();

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')
        ->once()
        ->andReturn(makeJudgeResponse());

    $runner = makeRunner(fn (string $criteria) => $fakeJudge);

    $result = $runner->run($log);

    expect($result)->not->toBeNull()
        ->and($result->overallScore)->toBe(82)
        ->and($result->criteria)->toHaveCount(3)
        ->and($result->criteria[0]->name)->toBe('accuracy')
        ->and($result->criteria[0]->score)->toBe(85)
        ->and($result->summary)->toContain('CTA');

    $evaluation = AiEvaluation::first();
    expect($evaluation)->not->toBeNull()
        ->and($evaluation->ai_response_log_id)->toBe($log->id)
        ->and($evaluation->agent)->toBe('App\\Ai\\Agents\\ContentWriterAgent')
        ->and($evaluation->overall_score)->toBe(82)
        ->and($evaluation->scorer)->toBeNull();
});

it('uses an explicit scorer when one is registered for the agent', function (): void {
    $explicitScorer = new class extends Scorer
    {
        public function agent(): string
        {
            return 'App\\Ai\\Agents\\ContentWriterAgent';
        }

        public function criteria(): array
        {
            return ['no_placeholders' => 'No placeholder text in output.'];
        }
    };

    config()->set('ai-companion.evaluation.scorers', [$explicitScorer]);

    $log = makeResponseLog();

    $capturedCriteria = '';
    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')->once()->andReturn(makeJudgeResponse());

    $runner = makeRunner(function (string $criteria) use ($fakeJudge, &$capturedCriteria): LlmJudge {
        $capturedCriteria = $criteria;

        return $fakeJudge;
    });

    $runner->run($log);

    expect($capturedCriteria)->toContain('no_placeholders');

    $evaluation = AiEvaluation::first();
    expect($evaluation->scorer)->not->toBeNull();
});

it('returns null and writes no row when the judge call throws', function (): void {
    $log = makeResponseLog();

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')->andThrow(new RuntimeException('timeout'));

    $runner = makeRunner(fn (string $criteria) => $fakeJudge);

    $result = $runner->run($log);

    expect($result)->toBeNull()
        ->and(AiEvaluation::count())->toBe(0);
});

it('includes agent instructions in the prompt when they are stored', function (): void {
    $log = makeResponseLog(['instructions' => 'Always write in British English.']);

    $capturedPrompt = '';
    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')
        ->once()
        ->withArgs(function (string $prompt) use (&$capturedPrompt): bool {
            $capturedPrompt = $prompt;

            return true;
        })
        ->andReturn(makeJudgeResponse());

    $runner = makeRunner(fn (string $criteria) => $fakeJudge);
    $runner->run($log);

    expect($capturedPrompt)->toContain('Always write in British English.');
});

it('omits the agent instructions section when instructions are null', function (): void {
    $log = makeResponseLog(['instructions' => null]);

    $capturedPrompt = '';
    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')
        ->once()
        ->withArgs(function (string $prompt) use (&$capturedPrompt): bool {
            $capturedPrompt = $prompt;

            return true;
        })
        ->andReturn(makeJudgeResponse());

    $runner = makeRunner(fn (string $criteria) => $fakeJudge);
    $runner->run($log);

    expect($capturedPrompt)->not->toContain('AGENT INSTRUCTIONS');
});

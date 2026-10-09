<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ClassifierEvalTarget;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\Data\Answer;
use Throwable;

/**
 * Classifies a single dataset row and scores the answers — the classification
 * counterpart of {@see RowEvaluator}. Holds no reference to the calling
 * Command, so it can run inside a forked process by a ConcurrencyRunner.
 */
final readonly class ClassificationRowEvaluator
{
    public function __construct(
        private ClassifierEvalTarget $target,
        private Evaluator $evaluator,
        private ?string $provider,
        private ?string $model,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function evaluate(array $row): RowEvaluationResult
    {
        $case = null;

        try {
            $case = $this->target->classification($row);
            $expected = array_map(fn (ExpectedAnswer $answer): array => $answer->toArray(), $case->expected);

            $startedAt = microtime(true);
            $response = Classification::of($case->state, $case->attachments)
                ->questions($case->questions)
                ->classify($this->provider, $this->model);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $output = array_map(fn (Answer $answer): array => $answer->toArray(), $response->answers);

            $scores = $this->evaluator->evaluate(new EvalSubject(
                output: $output,
                input: ['state' => $case->state],
                answers: $response->answers,
                expectedAnswers: $case->expected,
            ));

            return new RowEvaluationResult(
                event: new ExperimentEventData(
                    input: [
                        'state' => $case->state,
                        'questions' => array_map(fn (Question $question): array => $question->toArray(), $case->questions),
                    ],
                    output: $output,
                    scores: $scores,
                    metadata: EvalRunMetadata::forRow(
                        $row,
                        promptName: null,
                        promptVersion: null,
                        model: $response->meta->model,
                        provider: $response->meta->provider,
                    ),
                    metrics: new EvalRunMetrics(
                        latencyMs: $latencyMs,
                        promptTokens: $response->usage->inputTokens,
                        completionTokens: $response->usage->outputTokens,
                        tokens: $response->usage->inputTokens + $response->usage->outputTokens,
                    ),
                    expected: $expected === [] ? null : $expected,
                ),
                failure: null,
            );
        } catch (Throwable $exception) {
            $label = $case === null ? $row : $case->state;

            return new RowEvaluationResult(
                event: null,
                failure: sprintf('%s — %s', Str::limit(is_string($label) ? $label : (string) json_encode($label), 40), $exception->getMessage()),
            );
        }
    }
}

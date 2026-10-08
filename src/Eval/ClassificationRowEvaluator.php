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
 * counterpart of {@see RowEvaluator}. Stateless, so it can run inside a forked
 * process by a ConcurrencyRunner.
 */
final readonly class ClassificationRowEvaluator
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function evaluate(
        array $row,
        ClassifierEvalTarget $target,
        Evaluator $evaluator,
        ?string $provider,
        ?string $model,
    ): RowEvaluationResult {
        $case = $target->classification($row);
        $expected = array_map(fn (ExpectedAnswer $answer): array => $answer->toArray(), $case->expected);

        try {
            $startedAt = microtime(true);
            $response = Classification::of($case->state, $case->attachments)
                ->questions($case->questions)
                ->classify($provider, $model);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $output = array_map(fn (Answer $answer): array => $answer->toArray(), $response->answers);

            $scores = $evaluator->evaluate(new EvalSubject(
                output: $output,
                input: ['state' => $case->state, 'expected' => $expected],
                answers: $response->answers,
                expectedAnswers: $case->expected,
            ));

            $tags = $row['tags'] ?? null;

            return new RowEvaluationResult(
                event: new ExperimentEventData(
                    input: [
                        'state' => $case->state,
                        'questions' => array_map(fn (Question $question): array => $question->toArray(), $case->questions),
                    ],
                    output: $output,
                    scores: $scores,
                    metadata: new EvalRunMetadata(
                        promptName: null,
                        promptVersion: null,
                        model: $response->meta->model,
                        provider: $response->meta->provider,
                        tags: is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
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
            return new RowEvaluationResult(
                event: null,
                failure: sprintf('%s — %s', Str::limit(is_string($case->state) ? $case->state : (string) json_encode($case->state), 40), $exception->getMessage()),
            );
        }
    }
}

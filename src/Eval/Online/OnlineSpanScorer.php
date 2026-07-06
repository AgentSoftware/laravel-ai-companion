<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Online;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\RequiresExpected;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Evaluator;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs a target's local PHP scorers against its recent production spans and
 * merges the scores back onto those spans. Every merge also writes a sentinel
 * score "{key}_online" — the server-side marker that makes "unscored" an
 * exact BTQL filter, so re-runs are idempotent with no local state.
 */
final readonly class OnlineSpanScorer
{
    public function __construct(
        private BraintrustApi $api,
        private SpanSampler $sampler,
    ) {}

    /**
     * @return int spans scored
     */
    public function score(EvalTarget $target, float $sampleRate, int $lookbackMinutes, int $limit = 200): int
    {
        $scorers = collect($target->scorers());

        [$online, $skipped] = $scorers->partition(fn (Scorer $scorer): bool => ! $scorer instanceof RequiresExpected);

        if ($skipped->isNotEmpty()) {
            Log::warning('Online scoring skipped scorers that require expected context.', [
                'target' => $target->key(),
                'skipped' => $skipped->map(fn (Scorer $scorer): string => $scorer::class)->all(),
            ]);
        }

        if ($online->isEmpty()) {
            return 0;
        }

        $sentinel = $this->sentinelName($target);
        $evaluator = new Evaluator($online->values()->all());

        $events = collect($this->api->unscoredSpans(
            agentName: Str::studly($target->key()),
            scoreName: $sentinel,
            lookbackMinutes: $lookbackMinutes,
            limit: $limit,
        ))
            ->filter(fn (array $span): bool => is_string($span['id'] ?? null)
                && $this->sampler->selects($span['id'], $sampleRate))
            ->map(fn (array $span): ?array => rescue(
                fn (): array => $this->mergeEvent($span, $evaluator, $sentinel),
                rescue: null,
                report: false,
            ))
            ->filter()
            ->values();

        if ($events->isEmpty()) {
            return 0;
        }

        $this->api->mergeScores($events->all());

        return $events->count();
    }

    /**
     * @param  array<string, mixed>  $span
     * @return array<string, mixed>
     */
    private function mergeEvent(array $span, Evaluator $evaluator, string $sentinel): array
    {
        $output = $span['output'] ?? '';
        $prompt = (string) (is_array($span['input'] ?? null)
            ? data_get($span, 'input.prompt', data_get($span, 'input.input', ''))
            : ($span['input'] ?? ''));

        $subject = new EvalSubject(
            output: is_array($output) ? $output : ['text' => (string) $output],
            context: null,
            // The span's prompt is the judge's reference: exposed under 'prompt' and
            // 'brief' (LlmJudgeScorer's default input key), plus the output text.
            input: [
                'text' => is_array($output) ? (string) ($output['text'] ?? '') : (string) $output,
                'prompt' => $prompt,
                'brief' => $prompt,
            ],
        );

        $scores = collect($evaluator->evaluate($subject))
            ->mapWithKeys(fn (Score $score): array => [$score->name => $score->score])
            ->put($sentinel, 1.0)
            ->all();

        return ['id' => $span['id'], '_is_merge' => true, 'scores' => $scores];
    }

    private function sentinelName(EvalTarget $target): string
    {
        return Str::snake(str_replace('-', '_', $target->key())).'_online';
    }
}

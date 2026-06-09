<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation;

use AgentSoftware\LaravelAiCompanion\Evaluation\Judge\LlmJudge;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\CriterionResult;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\EvaluationResult;
use AgentSoftware\LaravelAiCompanion\Evaluation\Scorers\AutoInferredScorer;
use AgentSoftware\LaravelAiCompanion\Evaluation\Scorers\Scorer;
use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class EvaluationRunner
{
    /** @param Closure(string): LlmJudge|null $judgeFactory */
    public function __construct(private readonly ?Closure $judgeFactory = null) {}

    public function run(AiResponseLog $log): ?EvaluationResult
    {
        if (! config('ai-companion.evaluation.enabled', true)) {
            return null;
        }

        $scorer = $this->resolveScorer($log->agent);
        $criteriaPrompt = $this->buildCriteriaPrompt($scorer, $log->instructions);
        $judge = ($this->judgeFactory ?? fn (string $p): LlmJudge => new LlmJudge($p))($criteriaPrompt);
        $model = config('ai-companion.evaluation.model', 'claude-haiku-4-5-20251001');
        $provider = config('ai-companion.evaluation.provider', 'anthropic');

        try {
            $response = $judge->prompt($this->buildLogPrompt($log), provider: $provider, model: $model);

            if (! $response instanceof StructuredAgentResponse) {
                Log::warning('LlmJudge returned a non-structured response', [
                    'agent' => $log->agent,
                    'log_id' => $log->id,
                ]);

                return null;
            }

            /** @var array{criteria: list<array{name: string, score: int, feedback: string}>, summary: string} $structured */
            $structured = $response->structured;
            $result = EvaluationResult::fromArray($structured, $model);

            if (empty($result->criteria)) {
                Log::warning('LlmJudge returned empty criteria — skipping evaluation', [
                    'agent' => $log->agent,
                    'log_id' => $log->id,
                ]);

                return null;
            }

            AiEvaluation::create([
                'ai_response_log_id' => $log->id,
                'agent' => $log->agent,
                'scorer' => $scorer instanceof AutoInferredScorer ? null : $scorer::class,
                'overall_score' => $result->overallScore,
                'criteria' => array_map(
                    static fn (CriterionResult $c): array => $c->toArray(),
                    $result->criteria,
                ),
                'summary' => $result->summary,
                'judge_model' => $result->judgeModel,
            ]);

            return $result;
        } catch (Throwable $e) {
            Log::error('AI evaluation failed', [
                'agent' => $log->agent,
                'log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveScorer(string $agentClass): Scorer
    {
        /** @var list<Scorer|class-string<Scorer>> $scorers */
        $scorers = config('ai-companion.evaluation.scorers', []);

        foreach ($scorers as $scorer) {
            $instance = is_string($scorer) ? new $scorer : $scorer;

            if ($instance->agent() === $agentClass) {
                return $instance;
            }
        }

        return new AutoInferredScorer($agentClass);
    }

    private function buildCriteriaPrompt(Scorer $scorer, ?string $instructions): string
    {
        $criteria = $scorer->criteria();

        if ($criteria === []) {
            if ($instructions !== null) {
                return 'Infer 3–5 appropriate evaluation criteria from the agent instructions provided. '
                    .'Choose criteria that best assess whether the agent achieved its stated purpose.';
            }

            return 'No agent instructions are available. Evaluate on general quality criteria: '
                .'accuracy, clarity, helpfulness, completeness, and appropriate tone.';
        }

        $lines = array_map(
            static fn (string $name, string $description): string => "- {$name}: {$description}",
            array_keys($criteria),
            $criteria,
        );

        return "Evaluate against these specific criteria:\n".implode("\n", $lines);
    }

    private function buildLogPrompt(AiResponseLog $log): string
    {
        $parts = [];

        if ($log->instructions !== null) {
            $parts[] = "--- AGENT INSTRUCTIONS ---\n{$log->instructions}";
        }

        $parts[] = "--- USER INPUT ---\n{$log->prompt}";

        $response = $log->response;
        $responseText = is_array($response)
            ? (json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: json_encode($response) ?: '{}')
            : (string) $response;

        $parts[] = "--- AGENT RESPONSE ---\n{$responseText}";

        return implode("\n\n", $parts);
    }
}

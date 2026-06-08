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
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class EvaluationRunner
{
    /** @param Closure(string): LlmJudge|null $judgeFactory */
    public function __construct(private readonly ?Closure $judgeFactory = null) {}

    public function run(AiResponseLog $log): ?EvaluationResult
    {
        $scorer = $this->resolveScorer($log->agent);
        $criteriaPrompt = $this->buildCriteriaPrompt($scorer);
        $judge = ($this->judgeFactory ?? fn (string $p): LlmJudge => new LlmJudge($p))($criteriaPrompt);
        $model = config('ai-companion.evaluation.model', 'claude-haiku-4-5-20251001');

        try {
            $response = $judge->prompt($this->buildLogPrompt($log), model: $model);

            if (! $response instanceof StructuredAgentResponse) {
                return null;
            }

            /** @var array{overall_score: int, criteria: list<array{name: string, score: int, feedback: string}>, summary: string} $structured */
            $structured = $response->structured;
            $result = EvaluationResult::fromArray($structured, $model);

            AiEvaluation::create([
                'ai_response_log_id' => $log->id,
                'agent'              => $log->agent,
                'scorer'             => $scorer instanceof AutoInferredScorer ? null : $scorer::class,
                'overall_score'      => $result->overallScore,
                'criteria'           => array_map(
                    static fn (CriterionResult $c): array => $c->toArray(),
                    $result->criteria,
                ),
                'summary'    => $result->summary,
                'judge_model' => $result->judgeModel,
            ]);

            return $result;
        } catch (Throwable) {
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

    private function buildCriteriaPrompt(Scorer $scorer): string
    {
        $criteria = $scorer->criteria();

        if ($criteria === []) {
            return 'Infer 3–5 appropriate evaluation criteria from the agent instructions provided. '
                . 'Choose criteria that best assess whether the agent achieved its stated purpose.';
        }

        $lines = array_map(
            static fn (string $name, string $description): string => "- {$name}: {$description}",
            array_keys($criteria),
            $criteria,
        );

        return "Evaluate against these specific criteria:\n" . implode("\n", $lines);
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
            ? json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : (string) $response;

        $parts[] = "--- AGENT RESPONSE ---\n{$responseText}";

        return implode("\n\n", $parts);
    }
}

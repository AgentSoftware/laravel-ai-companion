<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Judges\JudgeAgent;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use Illuminate\Support\Arr;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Data-driven LLM-as-judge scorer: define the criterion as a rubric string
 * rather than a bespoke judge class. Reads the reference from the subject input
 * and the candidate from the output, asks {@see JudgeAgent} to rate it 0–scale
 * against the rubric, and normalises the rating to 0–1.
 */
final class LlmJudgeScorer implements Scorer
{
    public function __construct(
        private string $name,
        private string $rubric,
        private int $scale = 10,
        private string $input = 'brief',
        private string $output = 'name',
    ) {}

    public function score(EvalSubject $subject): Score
    {
        $reference = trim((string) ($subject->input[$this->input] ?? ''));
        $candidate = trim((string) ($subject->output[$this->output] ?? ''));

        if ($reference === '' || $candidate === '') {
            return new Score($this->name, 0.0, ['reason' => 'missing input or output']);
        }

        /** @var StructuredAgentResponse $response */
        $response = JudgeAgent::make($this->rubric, $reference, $this->scale)->prompt(
            $candidate,
            [],
            config('ai-companion.eval.judge.provider'),
            config('ai-companion.eval.judge.model'),
        );

        $payload = $response->toArray();
        $rating = max(0, min($this->scale, (int) Arr::get($payload, 'rating', 0)));

        return new Score($this->name, $rating / $this->scale, [
            'rating' => $rating,
            'reasoning' => Arr::string($payload, 'reasoning', ''),
        ]);
    }
}

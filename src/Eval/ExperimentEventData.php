<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

final readonly class ExperimentEventData
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     * @param  array<string, float>  $scores
     */
    public function __construct(
        public array $input,
        public array $output,
        public array $scores,
        public EvalRunMetadata $metadata,
        public EvalRunMetrics $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'scores' => $this->scores,
            'metadata' => $this->metadata->toArray(),
            'metrics' => $this->metrics->toArray(),
        ];
    }
}

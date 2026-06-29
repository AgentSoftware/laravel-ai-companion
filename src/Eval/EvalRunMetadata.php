<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

final readonly class EvalRunMetadata
{
    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public ?string $promptName,
        public int|string|null $promptVersion,
        public ?string $model,
        public ?string $provider,
        public array $tags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prompt_name' => $this->promptName,
            'prompt_version' => $this->promptVersion,
            'model' => $this->model,
            'provider' => $this->provider,
            'tags' => $this->tags,
        ];
    }
}

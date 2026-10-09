<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

final readonly class EvalRunMetadata
{
    /**
     * @param  array<int, string>  $tags
     * @param  int|string|null  $rowId  The dataset row's `id`, so a row can be matched across experiments
     */
    public function __construct(
        public ?string $promptName,
        public int|string|null $promptVersion,
        public ?string $model,
        public ?string $provider,
        public array $tags,
        public int|string|null $rowId = null,
    ) {}

    /**
     * Metadata for a dataset row's run: the row's `id` and string `tags` are
     * read from the row itself.
     *
     * @param  array<string, mixed>  $row
     */
    public static function forRow(array $row, ?string $promptName, int|string|null $promptVersion, ?string $model, ?string $provider): self
    {
        $id = $row['id'] ?? null;
        $tags = $row['tags'] ?? null;

        return new self(
            promptName: $promptName,
            promptVersion: $promptVersion,
            model: $model,
            provider: $provider,
            tags: is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
            rowId: is_int($id) || is_string($id) ? $id : null,
        );
    }

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
            'row_id' => $this->rowId,
        ];
    }
}

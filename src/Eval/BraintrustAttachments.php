<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;

/**
 * Replaces every `braintrust_attachment` reference in a dataset row, at any
 * depth, with an SDK file holding its downloaded bytes. Each attachment is
 * downloaded once per instance, so one per eval run keeps repeated photos cheap.
 */
final class BraintrustAttachments
{
    /** @var array<string, string> base64 contents keyed by attachment key */
    private array $downloaded = [];

    public function __construct(private readonly BraintrustApi $api) {}

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $row
     * @return array<TKey, mixed>
     */
    public function resolve(array $row): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            ! is_array($value) => $value,
            ($value['type'] ?? null) === 'braintrust_attachment' => $this->file($value),
            default => $this->resolve($value),
        }, $row);
    }

    /**
     * @param  array<array-key, mixed>  $reference
     */
    private function file(array $reference): File
    {
        $key = (string) $reference['key'];
        $filename = (string) ($reference['filename'] ?? $key);
        $contentType = (string) ($reference['content_type'] ?? 'application/octet-stream');

        $this->downloaded[$key] ??= base64_encode($this->api->attachment($key, $filename, $contentType));

        $file = str_starts_with($contentType, 'image/')
            ? new Base64Image($this->downloaded[$key], $contentType)
            : new Base64Document($this->downloaded[$key], $contentType);

        return $file->as($filename);
    }
}

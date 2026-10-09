<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use Illuminate\Support\Facades\File as Filesystem;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;

/**
 * Replaces every `braintrust_attachment` reference in a dataset row, at any
 * depth, with an SDK file pointing at its download in `$directory`. Files are
 * local rather than base64 because rows are serialized into each forked
 * process's environment, which cannot hold image-sized payloads. Each
 * attachment is downloaded once; the caller deletes the directory after the run.
 */
final readonly class BraintrustAttachments
{
    public function __construct(
        private BraintrustApi $api,
        private string $directory,
    ) {}

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
        $path = $this->directory.'/'.sha1($key);

        if (! Filesystem::exists($path)) {
            Filesystem::ensureDirectoryExists($this->directory, 0700);
            Filesystem::put($path, $this->api->attachment($key, $filename, $contentType));
        }

        $file = str_starts_with($contentType, 'image/')
            ? new LocalImage($path, $contentType)
            : new LocalDocument($path, $contentType);

        return $file->as($filename);
    }
}

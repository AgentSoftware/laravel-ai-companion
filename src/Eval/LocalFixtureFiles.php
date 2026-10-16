<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use Closure;
use Illuminate\Support\Facades\File as Filesystem;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use RuntimeException;

/**
 * Finds every `local_file` and `fixture` reference in a dataset row, at any depth.
 * A `local_file` is `{"type": "local_file", "path": "tests/Fixtures/eval/epc.pdf"}`
 * with an optional `filename` and `content_type`; the path is relative to the app
 * base path and may not leave it. A `fixture` is `{"type": "fixture", "name":
 * "epc.pdf"}`, a file inside the configured fixtures folder, with the same
 * optional keys. Resolving swaps each reference for an SDK file
 * pointing at the fixture in place, so rows stay small enough for the forked
 * processes' environments.
 */
final readonly class LocalFixtureFiles
{
    public const string TYPE = 'local_file';

    public const string FIXTURE_TYPE = 'fixture';

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $row
     * @return array<TKey, mixed>
     */
    public function resolve(array $row): array
    {
        return $this->replace($row, function (string $path, string $filename, string $contentType): LocalImage|LocalDocument {
            $file = str_starts_with($contentType, 'image/')
                ? new LocalImage($path, $contentType)
                : new LocalDocument($path, $contentType);

            return $file->as($filename);
        });
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $row
     * @param  Closure(string $path, string $filename, string $contentType): mixed  $replacement
     * @return array<TKey, mixed>
     */
    public function replace(array $row, Closure $replacement): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            ! is_array($value) => $value,
            in_array($value['type'] ?? null, [self::TYPE, self::FIXTURE_TYPE], true) => $this->replaceReference($value, $replacement),
            default => $this->replace($value, $replacement),
        }, $row);
    }

    /**
     * @param  array<array-key, mixed>  $reference
     * @param  Closure(string $path, string $filename, string $contentType): mixed  $replacement
     */
    private function replaceReference(array $reference, Closure $replacement): mixed
    {
        $path = $reference['type'] === self::FIXTURE_TYPE
            ? EvalFixture::path((string) ($reference['name'] ?? ''))
            : $this->localFilePath((string) ($reference['path'] ?? ''));

        return $replacement(
            $path,
            (string) ($reference['filename'] ?? basename($path)),
            (string) ($reference['content_type'] ?? (Filesystem::mimeType($path) ?: 'application/octet-stream')),
        );
    }

    private function localFilePath(string $relative): string
    {
        $path = realpath(base_path($relative));

        if ($path === false || ! is_file($path)) {
            throw new RuntimeException("Eval fixture file [{$relative}] does not exist.");
        }

        if (! str_starts_with($path, (string) realpath(base_path()).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Eval fixture file [{$relative}] is outside the application base path.");
        }

        return $path;
    }
}

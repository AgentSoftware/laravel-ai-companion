<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use RuntimeException;

/**
 * Locates a file in the eval fixtures folder, `ai-companion.eval.fixtures_path`
 * (default `resources/ai/fixtures`, relative to the app base path). A name may
 * include subfolders but may not leave the folder.
 */
final class EvalFixture
{
    public static function path(string $name): string
    {
        $folder = (string) config('ai-companion.eval.fixtures_path');
        $path = realpath(base_path($folder.'/'.$name));

        if ($path === false || ! is_file($path)) {
            throw new RuntimeException("Eval fixture [{$name}] does not exist in [{$folder}].");
        }

        if (! str_starts_with($path, (string) realpath(base_path($folder)).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Eval fixture [{$name}] is outside the fixtures folder [{$folder}].");
        }

        return $path;
    }

    public static function file(string $name): LocalImage|LocalDocument
    {
        return (new LocalFixtureFiles)->resolve([['type' => LocalFixtureFiles::FIXTURE_TYPE, 'name' => $name]])[0];
    }
}

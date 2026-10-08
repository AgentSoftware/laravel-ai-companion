<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use Closure;
use Laravel\Ai\Files\LocalImage;

/**
 * A forked task must be declared on a class the child process can autoload,
 * which Pest's generated test classes are not.
 */
final class AttachmentContentLength
{
    public static function task(LocalImage $attachment): Closure
    {
        return static function () use ($attachment): int {
            return strlen($attachment->content());
        };
    }
}

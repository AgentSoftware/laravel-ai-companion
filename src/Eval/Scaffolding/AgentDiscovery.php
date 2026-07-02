<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Finds concrete Agent implementations under a PSR-4 root in the consuming
 * app (defaults are app_path()/app namespace at the call site).
 */
final readonly class AgentDiscovery
{
    public function __construct(
        private string $path,
        private string $namespace,
    ) {}

    /** @return array<int, class-string> */
    public function discover(): array
    {
        if (! File::isDirectory($this->path)) {
            return [];
        }

        return collect(File::allFiles($this->path))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
            ->map(fn (SplFileInfo $file): string => $this->namespace.Str::of($file->getRelativePathname())
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\')
                ->toString())
            ->filter($this->isConcreteAgent(...))
            ->sort()
            ->values()
            ->all();
    }

    private function isConcreteAgent(string $class): bool
    {
        try {
            if (! class_exists($class)) {
                return false;
            }

            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return false;
        }

        return $reflection->implementsInterface(Agent::class) && $reflection->isInstantiable();
    }
}

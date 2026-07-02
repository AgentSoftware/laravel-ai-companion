<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use ReflectionClass;
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

        $agents = [];

        foreach (File::allFiles($this->path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->namespace.Str::of($file->getRelativePathname())
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\')
                ->toString();

            try {
                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                continue;
            }

            if ($reflection->implementsInterface(Agent::class) && $reflection->isInstantiable()) {
                $agents[] = $class;
            }
        }

        sort($agents);

        return $agents;
    }
}

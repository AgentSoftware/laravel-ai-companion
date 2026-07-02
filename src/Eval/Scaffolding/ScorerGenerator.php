<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Support\Str;

final readonly class ScorerGenerator
{
    public function generate(string $namespace, string $class): string
    {
        $stub = (string) file_get_contents(dirname(__DIR__, 3).'/stubs/eval-scorer.stub');

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ name }}'],
            [$namespace, $class, Str::of($class)->beforeLast('Scorer')->snake()->toString()],
            $stub,
        );
    }
}

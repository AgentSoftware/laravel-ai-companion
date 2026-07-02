<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scaffolding;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Renders an EvalTarget class for an agent, mapping the agent's constructor
 * parameters from dataset row keys via reflection.
 */
final readonly class TargetGenerator
{
    /** @param array<int, ScorerEntry> $scorers */
    public function generate(
        string $namespace,
        string $class,
        string $agentClass,
        string $key,
        string $label,
        string $datasetPath,
        array $scorers,
    ): string {
        $stub = (string) file_get_contents(dirname(__DIR__, 3).'/stubs/eval-target.stub');

        $imports = collect($scorers)
            ->flatMap(fn (ScorerEntry $entry): array => $entry->imports)
            ->push($agentClass)
            ->unique()
            ->sort()
            ->map(fn (string $fqcn): string => "use {$fqcn};")
            ->implode("\n");

        $scorerLines = collect($scorers)
            ->map(fn (ScorerEntry $entry): string => "            {$entry->code},")
            ->implode("\n");

        return str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ class }}', '{{ key }}', '{{ label }}', '{{ dataset }}', '{{ scorers }}', '{{ agentShort }}', '{{ agentArgs }}'],
            [$namespace, $imports, $class, $key, $label, $datasetPath, $scorerLines, class_basename($agentClass), $this->agentArgs($agentClass)],
            $stub,
        );
    }

    /** @param class-string $agentClass */
    private function agentArgs(string $agentClass): string
    {
        $constructor = new ReflectionClass($agentClass)->getConstructor();

        if ($constructor === null) {
            return '';
        }

        return collect($constructor->getParameters())
            ->map(fn (ReflectionParameter $parameter): string => '            '.$this->argFor($parameter).',')
            ->implode("\n");
    }

    private function argFor(ReflectionParameter $parameter): string
    {
        $name = $parameter->getName();
        $rowKey = Str::snake($name);
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return "{$name}: app(\\{$type->getName()}::class) /* TODO: verify this resolves for evals */";
        }

        if ($type instanceof ReflectionNamedType && in_array($type->getName(), ['string', 'int', 'float', 'bool'], true)) {
            $cast = $type->getName();
            $default = $parameter->isDefaultValueAvailable()
                ? var_export($parameter->getDefaultValue(), true)
                : match ($cast) {
                    'string' => "''",
                    'int' => '0',
                    'float' => '0.0',
                    'bool' => 'false',
                };

            return "{$name}: ({$cast}) (\$row['{$rowKey}'] ?? {$default})";
        }

        return "{$name}: \$row['{$rowKey}'] ?? null /* TODO: map from a dataset row key */";
    }
}

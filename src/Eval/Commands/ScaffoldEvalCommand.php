<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Commands;

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\AgentDiscovery;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustDatasetSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustLogsSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\DatasetSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ResponseLogSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerEntry;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerGenerator;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\TargetGenerator;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\LlmJudgeScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\MatchScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\RangeScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\ToolRoutingScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Interactive wizard: pick an agent, pull historical traffic into a dataset
 * JSON file, and scaffold an EvalTarget (+ scorer stubs) in the consuming app.
 */
class ScaffoldEvalCommand extends Command
{
    protected $signature = 'ai:eval:scaffold';

    protected $description = 'Interactively scaffold an eval: dataset JSON, EvalTarget, and scorers';

    public function handle(): int
    {
        $path = (string) (config('ai-companion.eval.scaffold.agent_path') ?? app_path());
        $namespace = (string) (config('ai-companion.eval.scaffold.agent_namespace') ?? $this->appNamespace());

        $agents = new AgentDiscovery(path: $path, namespace: $namespace)->discover();

        if ($agents === []) {
            error('No classes implementing Laravel\Ai\Contracts\Agent found under '.$path);

            return self::FAILURE;
        }

        $agentClass = (string) search(
            label: 'Which agent is this eval for?',
            options: fn (string $value): array => $this->agentOptions($agents, $value),
            placeholder: 'Start typing an agent name…',
            scroll: 10,
        );

        $defaultKey = Str::of(class_basename($agentClass))->beforeLast('Agent')->kebab()->toString();
        $key = text(label: 'Eval key', default: $defaultKey, required: true);
        $label = text(label: 'Eval label', default: Str::headline($defaultKey), required: true);
        $datasetPath = "database/eval-datasets/{$key}.json";

        if (! $this->buildDataset($agentClass, $datasetPath)) {
            return self::FAILURE;
        }

        $scorers = $this->askScorers();

        return $this->writeTarget($agentClass, $key, $label, $datasetPath, $scorers) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Agent picker options: short class names, filtered by the typed search
     * term. Namespaces only appear when two agents share a basename.
     *
     * @param  array<int, string>  $agents
     * @return array<string, string>
     */
    private function agentOptions(array $agents, string $value): array
    {
        $matches = array_filter(
            $agents,
            fn (string $class): bool => $value === '' || Str::contains($class, $value, ignoreCase: true),
        );

        $basenames = array_count_values(array_map(class_basename(...), $agents));

        $options = [];

        foreach ($matches as $class) {
            $basename = class_basename($class);

            $options[$class] = $basenames[$basename] > 1
                ? sprintf('%s (%s)', $basename, Str::beforeLast($class, '\\'))
                : $basename;
        }

        return $options;
    }

    private function appNamespace(): string
    {
        try {
            return app()->getNamespace();
        } catch (Throwable) {
            return 'App\\';
        }
    }

    private function buildDataset(string $agentClass, string $datasetPath): bool
    {
        $source = select(label: 'Where should the dataset come from?', options: [
            'braintrust_dataset' => 'Existing Braintrust dataset',
            'braintrust_logs' => 'Recent Braintrust logs',
            'response_logs' => 'ai_response_logs table',
            'skip' => 'Skip — dataset file already exists',
        ]);

        if ($source === 'skip') {
            return true;
        }

        if (in_array($source, ['braintrust_dataset', 'braintrust_logs'], true) && blank(config('ai-companion.braintrust.api_key'))) {
            error('Braintrust is not configured. Set BRAINTRUST_API_KEY (and BRAINTRUST_API_URL for EU orgs).');

            return false;
        }

        try {
            $datasetSource = $this->makeSource($source, $agentClass);

            if ($datasetSource === null) {
                return false;
            }

            $limit = (int) text(label: 'How many rows?', default: '50', required: true);

            $fields = multiselect(
                label: 'Include in each row (prompt is always included)',
                options: ['expected' => 'Output (as "expected")', 'metadata' => 'Metadata (flattened scalars)'],
                default: ['expected', 'metadata'],
            );

            $rows = $datasetSource->fetch(
                limit: max(1, $limit),
                includeExpected: in_array('expected', $fields, true),
                includeMetadata: in_array('metadata', $fields, true),
            );
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return false;
        }

        if ($rows === []) {
            error('The source returned no rows — nothing to write.');

            return false;
        }

        $full = base_path($datasetPath);

        if (File::exists($full) && ! confirm("Overwrite existing {$datasetPath}?", default: false)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($full));
        File::put($full, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        info(sprintf('Wrote %d row(s) to %s', count($rows), $datasetPath));

        return true;
    }

    private function makeSource(string $source, string $agentClass): ?DatasetSource
    {
        return match ($source) {
            'response_logs' => new ResponseLogSource($agentClass),
            'braintrust_logs' => new BraintrustLogsSource(new BraintrustApi, class_basename($agentClass)),
            'braintrust_dataset' => $this->pickBraintrustDataset(),
            default => null,
        };
    }

    private function pickBraintrustDataset(): ?DatasetSource
    {
        $api = new BraintrustApi;
        $datasets = $api->datasets();

        if ($datasets === []) {
            error('No datasets found in the configured Braintrust project.');

            return null;
        }

        $id = select(
            label: 'Which Braintrust dataset?',
            options: collect($datasets)->mapWithKeys(fn (array $d): array => [$d['id'] => $d['name']])->all(),
        );

        return new BraintrustDatasetSource($api, (string) $id);
    }

    /** @return array<int, ScorerEntry> */
    private function askScorers(): array
    {
        $builtins = multiselect(label: 'Built-in scorers', options: [
            'match' => 'MatchScorer',
            'llm_judge' => 'LlmJudgeScorer',
            'range' => 'RangeScorer',
            'tool_routing' => 'ToolRoutingScorer',
        ]);

        $entries = [];

        foreach ($builtins as $builtin) {
            $entries[] = match ((string) $builtin) {
                'llm_judge' => new ScorerEntry(
                    code: sprintf(
                        'new LlmJudgeScorer(name: %s, rubric: %s)',
                        var_export(text(label: 'LLM judge name', default: 'quality', required: true), true),
                        var_export(text(label: 'LLM judge rubric', required: true), true),
                    ),
                    imports: [LlmJudgeScorer::class],
                ),
                'match' => new ScorerEntry(
                    code: "new MatchScorer(name: 'match', field: 'text', expected: 'expected') /* TODO: set field + expected row key */",
                    imports: [MatchScorer::class],
                ),
                'range' => new ScorerEntry(
                    code: "new RangeScorer(name: 'length', field: 'text', min: 1, max: 500) /* TODO: tune bounds */",
                    imports: [RangeScorer::class],
                ),
                'tool_routing' => new ScorerEntry(code: 'new ToolRoutingScorer', imports: [ToolRoutingScorer::class]),
                default => throw new LogicException("Unknown built-in scorer option: {$builtin}"),
            };
        }

        $custom = text(label: 'Custom scorer class names (comma-separated, blank for none)', default: '');

        foreach (array_filter(array_map(trim(...), explode(',', $custom))) as $name) {
            $class = Str::studly($name);
            $namespace = trim($this->appNamespace(), '\\').'\\Ai\\Eval\\Scorers';
            $path = app_path("Ai/Eval/Scorers/{$class}.php");

            if (! File::exists($path) || confirm("Overwrite existing {$class}?", default: false)) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, new ScorerGenerator()->generate($namespace, $class));
                info("Created app/Ai/Eval/Scorers/{$class}.php");
            }

            $entries[] = new ScorerEntry(code: "new {$class}", imports: ["{$namespace}\\{$class}"]);
        }

        return $entries;
    }

    /** @param array<int, ScorerEntry> $scorers */
    private function writeTarget(string $agentClass, string $key, string $label, string $datasetPath, array $scorers): bool
    {
        $class = class_basename($agentClass).'EvalTarget';
        $namespace = trim($this->appNamespace(), '\\').'\\Ai\\Eval\\Targets';
        $path = app_path("Ai/Eval/Targets/{$class}.php");

        if (File::exists($path) && ! confirm("Overwrite existing {$class}?", default: false)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, new TargetGenerator()->generate($namespace, $class, $agentClass, $key, $label, $datasetPath, $scorers));

        outro(sprintf(
            "Created app/Ai/Eval/Targets/%s.php\nNext: add %s\\%s::class to ai-companion.eval.targets, then run your eval command (see readme #evaluations).",
            $class,
            $namespace,
            $class,
        ));

        return true;
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Commands;

use AgentSoftware\LaravelAiCompanion\Eval\Js\JsScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\AgentDiscovery;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustDatasetSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustLogsSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\DatasetSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ResponseLogSource;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerEntry;
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
use function Laravel\Prompts\warning;

/**
 * Interactive wizard: pick an agent, pull historical traffic into a dataset
 * JSON file, and scaffold an EvalTarget (+ scorer stubs) in the consuming app.
 */
class ScaffoldEvalCommand extends Command
{
    protected $signature = 'ai:scaffold-eval';

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
            hint: 'This agent will be run against every dataset row and its answers scored.',
            scroll: 10,
        );

        $defaultKey = Str::of(class_basename($agentClass))->beforeLast('Agent')->kebab()->toString();
        $key = text(
            label: 'Eval key',
            default: $defaultKey,
            required: true,
            hint: 'Short id for this eval — used in the dataset filename and to run it: php artisan ai:eval <key>. The default is fine.',
        );
        $label = text(
            label: 'Eval label',
            default: Str::headline($defaultKey),
            required: true,
            hint: 'Human-friendly name shown in pickers and result banners. The default is fine.',
        );
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
        $basenames = collect($agents)->countBy(class_basename(...));

        return collect($agents)
            ->filter(fn (string $class): bool => $value === '' || Str::contains($class, $value, ignoreCase: true))
            ->mapWithKeys(fn (string $class): array => [
                $class => $basenames[class_basename($class)] > 1
                    ? sprintf('%s (%s)', class_basename($class), Str::beforeLast($class, '\\'))
                    : class_basename($class),
            ])
            ->all();
    }

    private function appNamespace(): string
    {
        // Falls back for apps whose composer.json has no autoloaded app namespace.
        return rescue(fn (): string => app()->getNamespace(), 'App\\', report: false);
    }

    private function appEvalNamespace(string $suffix): string
    {
        return trim($this->appNamespace(), '\\').'\\Ai\\Eval\\'.$suffix;
    }

    private function buildDataset(string $agentClass, string $datasetPath): bool
    {
        $source = select(
            label: 'Where should the test data come from?',
            options: [
                'braintrust_dataset' => 'A dataset you already curated in Braintrust',
                'braintrust_logs' => 'Recent production traffic logged to Braintrust (most common)',
                'response_logs' => 'The ai_response_logs database table (if you use the LogAiResponse middleware)',
                'skip' => 'Nowhere — I already have a dataset JSON file',
            ],
            hint: 'The eval replays real past prompts through the agent and scores the answers. This picks where those past prompts are pulled from.',
        );

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

            $limit = (int) text(
                label: 'How many past interactions should the dataset hold?',
                default: '50',
                required: true,
                hint: 'Each one becomes a test case: one agent call (real cost + time) per eval run. 10–20 is plenty to start; you can delete rows from the JSON later.',
            );

            $fields = multiselect(
                label: 'Each row always gets the prompt. What else should it keep?',
                options: [
                    'expected' => 'The answer the agent gave at the time — stored as "expected" so scorers can compare against it',
                    'metadata' => 'Context values from the log (model, tags, custom keys) — used to rebuild the agent per row',
                ],
                default: ['expected', 'metadata'],
                hint: 'Space toggles, enter confirms. Unsure? Keep both — you can always delete keys from the JSON later.',
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
            hint: 'Datasets from your Braintrust project — its rows will be copied into the local JSON file.',
        );

        return new BraintrustDatasetSource($api, (string) $id);
    }

    private function scorerEntryFor(string $builtin): ScorerEntry
    {
        if ($builtin === 'llm_judge') {
            return new ScorerEntry(
                code: sprintf(
                    'new LlmJudgeScorer(name: %s, rubric: %s)',
                    var_export(text(
                        label: 'LLM judge name',
                        default: 'quality',
                        required: true,
                        hint: 'The score\'s column name in results, e.g. "quality" or "on_brand".',
                    ), true),
                    var_export(text(
                        label: 'LLM judge rubric',
                        required: true,
                        placeholder: 'e.g. Every page slug in the answer must come from the input list, grouped logically.',
                        hint: 'Plain-English marking criteria — tell the judge what a 10/10 answer looks like for this agent.',
                    ), true),
                ),
                imports: [LlmJudgeScorer::class],
            );
        }

        if ($builtin === 'match') {
            return new ScorerEntry(
                code: "new MatchScorer(name: 'match', field: 'text', expected: 'expected') /* TODO: set field + expected row key */",
                imports: [MatchScorer::class],
            );
        }

        if ($builtin === 'range') {
            return new ScorerEntry(
                code: "new RangeScorer(name: 'length', field: 'text', min: 1, max: 500) /* TODO: tune bounds */",
                imports: [RangeScorer::class],
            );
        }

        if ($builtin === 'tool_routing') {
            return new ScorerEntry(code: 'new ToolRoutingScorer', imports: [ToolRoutingScorer::class]);
        }

        // @codeCoverageIgnoreStart
        throw new LogicException("Unknown built-in scorer option: {$builtin}");
        // @codeCoverageIgnoreEnd
    }

    /** @return array<int, ScorerEntry> */
    private function askScorers(): array
    {
        $builtins = multiselect(
            label: 'Which built-in scorers should judge the answers?',
            options: [
                'match' => 'MatchScorer — checks a field of the answer equals an expected value',
                'llm_judge' => 'LlmJudgeScorer — an LLM grades each answer against a rubric you write (most flexible)',
                'range' => 'RangeScorer — checks a field\'s length/count falls inside min–max bounds',
                'tool_routing' => 'ToolRoutingScorer — checks the agent called the tools the row expected',
            ],
            hint: 'Scorers give each answer a 0–1 score. Space toggles, enter confirms; pick none if you only want custom scorers.',
        );

        // Built-in entries first: LlmJudgeScorer prompts for its rubric here,
        // so this must run before the custom-names question to keep prompt order.
        $builtinEntries = collect($builtins)
            ->map(fn (int|string $builtin): ScorerEntry => $this->scorerEntryFor((string) $builtin));

        $custom = text(
            label: 'Custom scorer names (comma-separated, blank for none)',
            placeholder: 'e.g. ValidComplianceJson, no-hallucinated-urls',
            hint: 'Custom scorers are agent-specific JS checks the built-ins can\'t cover — run offline via Node, publishable to live traffic with ai:publish-eval. Any casing works. Press enter to skip.',
            default: '',
        );

        // headline() first so ValidComplianceJson, valid-compliance-json, and
        // "Valid Compliance Json" all converge on the same slug; names that
        // can't normalise to a valid slug are skipped with a warning.
        [$valid, $invalid] = Str::of($custom)
            ->explode(',')
            ->map(fn (string $name): string => Str::slug(Str::headline(trim($name)), '-'))
            ->filter()
            ->unique()
            ->partition(fn (string $slug): bool => (bool) preg_match('/^[a-z][a-z0-9-]*$/', $slug));

        $invalid->each(fn (string $slug) => warning("Skipping \"{$slug}\" — not a valid scorer name."));

        return $builtinEntries
            ->merge($valid->map($this->jsScorerEntry(...)))
            ->values()
            ->all();
    }

    private function jsScorerEntry(string $slug): ScorerEntry
    {
        $relative = "resources/ai/scorers/{$slug}.js";
        $path = base_path($relative);
        $exists = File::exists($path);

        if (! $exists || confirm("Overwrite existing {$slug}.js?", default: false)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, str_replace('{{ name }}', $slug, (string) file_get_contents(dirname(__DIR__, 3).'/stubs/eval-js-scorer.stub')));
            info(($exists ? 'Overwrote' : 'Created')." {$relative}");
        }

        return new ScorerEntry(
            code: sprintf("new JsScorer(base_path('%s'))", $relative),
            imports: [JsScorer::class],
        );
    }

    /** @param array<int, ScorerEntry> $scorers */
    private function writeTarget(string $agentClass, string $key, string $label, string $datasetPath, array $scorers): bool
    {
        $class = class_basename($agentClass).'EvalTarget';
        $namespace = $this->appEvalNamespace('Targets');
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

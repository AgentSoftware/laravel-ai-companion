<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Commands;

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalHarness;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\HasPromptAttachments;
use AgentSoftware\LaravelAiCompanion\Eval\EvalRunMetadata;
use AgentSoftware\LaravelAiCompanion\Eval\EvalRunMetrics;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Evaluator;
use AgentSoftware\LaravelAiCompanion\Eval\ExperimentEventData;
use AgentSoftware\LaravelAiCompanion\Eval\RepoInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Runs an AI agent over an eval dataset, scores each output, and pushes a
 * Braintrust experiment (or writes scored NDJSON when no API key is set).
 *
 * App-agnostic: the targets to run and the throwaway-world bootstrap come from
 * config (`ai-companion.eval.targets` and `ai-companion.eval.harness`). Extend
 * this command in the app and declare a signature carrying the target argument
 * plus the --dataset/--out/--provider/--model/--tag/--limit/--trials options.
 */
abstract class RunEvalCommand extends Command
{
    /**
     * Tool results in the transcript are for judging what the agent did, not
     * re-reading whole payloads — cap each one so a large API response doesn't
     * swamp the judge's context.
     */
    private const int TRANSCRIPT_RESULT_LIMIT = 500;

    /** @var array<int, string> */
    private array $failures = [];

    public function handle(ExperimentExporter $exporter): int
    {
        $harness = $this->harness();

        if ($harness === null) {
            error('No eval harness configured. Set ai-companion.eval.harness to an EvalHarness implementation.');

            return self::FAILURE;
        }

        $target = $this->resolveTarget();

        if ($target === null) {
            return self::FAILURE;
        }

        $rows = $this->filterDataset($this->loadDataset($target));

        if ($rows->isEmpty()) {
            error('Dataset is empty, missing, or filtered to nothing.');

            return self::FAILURE;
        }

        $evaluator = new Evaluator($target->scorers());
        $trials = max(1, (int) $this->option('trials'));

        // Run each row `trials` times; same input each time so Braintrust buckets
        // the trials together and reports variance.
        $runs = $rows->flatMap(fn (array $row): array => array_fill(0, $trials, $row))->values();

        intro(sprintf('%s · %d run(s)%s', $target->label(), $runs->count(), $this->option('model') ? ' · '.$this->option('model') : ''));

        $events = collect(progress(
            label: 'Scoring',
            steps: $runs,
            callback: fn (array $row): ?ExperimentEventData => $this->evaluateRow($row, $target, $evaluator, $harness),
            hint: 'Calls the model once per row — not fast.',
        ))->filter()->values();

        if ($this->failures !== []) {
            warning(count($this->failures).' run(s) failed:'.PHP_EOL.implode(PHP_EOL, $this->failures));
        }

        $first = $events->first();

        if ($first === null) {
            error('Every run failed — nothing to report.');

            return self::FAILURE;
        }

        $this->renderResults($events);

        if ($exporter->enabled()) {
            $experiment = $this->experimentName($target, $first);
            $id = $exporter->export($experiment, $events->all(), $harness->experimentMetadata(), $this->repoInfo());

            outro(sprintf('Pushed %d row(s) to Braintrust experiment "%s" (%s).', $events->count(), $experiment, $id));

            return self::SUCCESS;
        }

        $this->writeNdjson($target, $events->map(fn (ExperimentEventData $event): array => $event->toArray())->all());

        outro(sprintf('No Braintrust API key — wrote %d row(s) to %s', $events->count(), $this->outPath($target)));

        return self::SUCCESS;
    }

    private function harness(): ?EvalHarness
    {
        $class = config('ai-companion.eval.harness');

        if (! is_string($class) || $class === '') {
            return null;
        }

        $harness = app($class);

        return $harness instanceof EvalHarness ? $harness : null;
    }

    private function resolveTarget(): ?EvalTarget
    {
        $classes = array_values(array_filter((array) config('ai-companion.eval.targets', []), 'is_string'));

        /** @var Collection<string, EvalTarget> $targets */
        $targets = collect($classes)
            ->map(fn (string $class): EvalTarget => app($class))
            ->keyBy(fn (EvalTarget $target): string => $target->key());

        if ($targets->isEmpty()) {
            error('No eval targets configured. Add EvalTarget classes to ai-companion.eval.targets.');

            return null;
        }

        $key = $this->argument('target') ?? select(
            label: 'Which agent do you want to eval?',
            options: $targets->map(fn (EvalTarget $target): string => $target->label())->all(),
        );

        $target = $targets->get($key);

        if ($target === null) {
            error("Unknown eval target [{$key}]. Available: ".$targets->keys()->implode(', '));
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function evaluateRow(array $row, EvalTarget $target, Evaluator $evaluator, EvalHarness $harness): ?ExperimentEventData
    {
        $input = $target->promptInput($row);

        // Bootstrap a throwaway world, run the real agent, score it — then roll
        // everything back so the eval leaves no trace in the database.
        DB::beginTransaction();

        try {
            $environment = $harness->boot($row);

            $agent = $target->agent($environment, $row);

            $attachments = $target instanceof HasPromptAttachments
                ? $target->promptAttachments($row)
                : [];

            $startedAt = microtime(true);
            $response = $agent->prompt($input, $attachments, $this->option('provider'), $this->option('model'));
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $meta = $response->meta;
            $usage = $response->usage;
            $loggable = $agent instanceof HasLoggableProperties ? $agent->loggableProperties() : [];

            $toolCalls = $response->toolCalls->map(fn (ToolCall $call): string => $call->name)->values()->all();
            $transcript = $this->transcript($response);

            // Tool-using agents return a TextResponse with no structured payload —
            // capture the reply text, the tools it chose, and (for multi-step
            // runs) the transcript of what it did along the way.
            $output = $response instanceof StructuredAgentResponse
                ? $response->toArray()
                : [
                    'text' => $response->text,
                    'tool_calls' => $toolCalls,
                    ...($transcript === '' ? [] : ['transcript' => $transcript]),
                ];

            $subject = new EvalSubject($output, $harness->context($environment), [
                ...$target->subjectInput($row),
                'tool_calls' => $toolCalls,
                'tool_call_details' => $response->toolCalls
                    ->map(fn (ToolCall $call): array => ['name' => $call->name, 'arguments' => $call->arguments])
                    ->values()
                    ->all(),
                'transcript' => $transcript,
                'text' => $response->text,
            ]);
            $scores = $evaluator->evaluate($subject);

            $promptName = $loggable['prompt_name'] ?? null;
            $tags = $row['tags'] ?? null;

            return new ExperimentEventData(
                input: ['input' => $input],
                output: $output,
                scores: $scores,
                metadata: new EvalRunMetadata(
                    promptName: is_string($promptName) ? $promptName : null,
                    promptVersion: $this->scalarOrNull($loggable['prompt_version'] ?? null),
                    model: $meta->model,
                    provider: $meta->provider,
                    tags: is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
                ),
                metrics: new EvalRunMetrics(
                    latencyMs: $latencyMs,
                    promptTokens: $usage->promptTokens,
                    completionTokens: $usage->completionTokens,
                    tokens: $usage->promptTokens + $usage->completionTokens,
                ),
                expected: is_array($row['expected'] ?? null) ? $row['expected'] : null,
            );
        } catch (Throwable $exception) {
            $this->failures[] = sprintf('%s — %s', Str::limit($input, 40), $exception->getMessage());

            return null;
        } finally {
            DB::rollBack();
        }
    }

    private function scalarOrNull(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * Flatten the agent's message history — narration, tool calls with their
     * arguments, and truncated tool results — into a judge-readable transcript
     * of what a multi-step run actually did.
     */
    private function transcript(TextResponse $response): string
    {
        return $response->messages
            ->flatMap(fn (Message $message): array => match (true) {
                $message instanceof AssistantMessage => collect([trim($message->content ?? '')])
                    ->filter()
                    ->merge($message->toolCalls->map(
                        fn (ToolCall $call): string => sprintf('[tool] %s %s', $call->name, json_encode($call->arguments)),
                    ))
                    ->all(),
                $message instanceof ToolResultMessage => $message->toolResults
                    ->map(fn (ToolResult $result): string => sprintf(
                        '[result] %s %s',
                        $result->name,
                        Str::limit((string) json_encode($result->result), self::TRANSCRIPT_RESULT_LIMIT),
                    ))
                    ->all(),
                default => [],
            })
            ->implode("\n");
    }

    /**
     * Render the per-run scores as a coloured table.
     *
     * @param  Collection<int, ExperimentEventData>  $events
     */
    private function renderResults(Collection $events): void
    {
        $scoreNames = $events->flatMap(fn (ExperimentEventData $event): array => array_keys($event->scoreValues()))->unique()->values();

        $headers = [
            'Input',
            ...$scoreNames->map(fn (string $name): string => Str::headline($name))->all(),
            'ms',
            'tokens',
        ];

        $rows = $events->map(function (ExperimentEventData $event) use ($scoreNames): array {
            $values = $event->scoreValues();

            return [
                Str::limit((string) ($event->input['input'] ?? ''), 38),
                ...$scoreNames->map(fn (string $name): string => $this->scoreCell($values[$name]))->all(),
                (string) $event->metrics->latencyMs,
                (string) $event->metrics->tokens,
            ];
        })->all();

        table($headers, $rows);
    }

    private function scoreCell(float $score): string
    {
        $colour = match (true) {
            $score >= 0.8 => 'green',
            $score >= 0.5 => 'yellow',
            default => 'red',
        };

        return "<fg={$colour}>".number_format($score, 2).'</>';
    }

    /**
     * Encode the variables under test into the experiment name so a Braintrust
     * diff is legible from the name alone: agent, prompt version, model, and a
     * marker for any partial run. The resolved model is the source of truth — a
     * model-only override is ignored when the agent declares a provider failover
     * list, so name the experiment after what actually ran.
     */
    private function experimentName(EvalTarget $target, ExperimentEventData $event): string
    {
        $version = $event->metadata->promptVersion ?? 'dev';
        $model = $event->metadata->model ?? $this->option('model') ?? $this->option('provider') ?? 'default';

        $name = "{$target->key()}/v{$version}/{$model}";

        if (filled($this->option('tag'))) {
            $name .= '/tag-'.$this->option('tag');
        }

        if (filled($this->option('limit'))) {
            $name .= '/first-'.$this->option('limit');
        }

        return $name;
    }

    /**
     * Git metadata so Braintrust can auto-select the previous run on this branch
     * as the comparison baseline. Fields are null when not in a git repo.
     */
    private function repoInfo(): RepoInfo
    {
        return new RepoInfo(
            branch: $this->git('rev-parse --abbrev-ref HEAD'),
            commit: $this->git('rev-parse HEAD'),
            commitMessage: $this->git('log -1 --pretty=%s'),
            dirty: $this->gitDirty(),
        );
    }

    private function git(string $args): ?string
    {
        $result = Process::run("git {$args}");

        return $result->successful() ? (trim($result->output()) ?: null) : null;
    }

    private function gitDirty(): ?bool
    {
        $result = Process::run('git status --porcelain');

        return $result->successful() ? $result->output() !== '' : null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadDataset(EvalTarget $target): Collection
    {
        $path = base_path((string) ($this->option('dataset') ?: $target->defaultDataset()));

        if (! File::exists($path)) {
            return collect();
        }

        return collect(File::json($path));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterDataset(Collection $rows): Collection
    {
        $tag = $this->option('tag');
        $limit = $this->option('limit');

        return $rows
            ->when($tag !== null, fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => in_array($tag, is_array($row['tags'] ?? null) ? $row['tags'] : [], true),
            ))
            ->when($limit !== null, fn (Collection $rows): Collection => $rows->take((int) $limit))
            ->values();
    }

    private function outPath(EvalTarget $target): string
    {
        $default = rtrim((string) config('ai-companion.eval.output_path', storage_path('app/braintrust')), '/')."/{$target->key()}.ndjson";

        return (string) ($this->option('out') ?: $default);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function writeNdjson(EvalTarget $target, array $events): void
    {
        $path = $this->outPath($target);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, collect($events)->map(fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR))->implode("\n"));
    }
}

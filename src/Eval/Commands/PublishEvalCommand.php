<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Commands;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Js\JsScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * The publish boundary for JS scorers: everything stays local until an eval's
 * scorers are explicitly selected here. Each ticked scorer is synced to
 * Braintrust, smoke-tested in the REAL sandbox, and only then wired into the
 * target's online scoring rule.
 */
class PublishEvalCommand extends Command
{
    protected $signature = 'ai:publish-eval
        {--target= : Eval target key to publish}
        {--scorers= : Comma-separated JS scorer names to publish}
        {--sample= : Fraction of live traffic to score (0.0-1.0)}';

    protected $description = 'Publish an eval\'s JS scorers to Braintrust and enable online scoring';

    public function handle(BraintrustApi $api): int
    {
        $targets = collect((array) config('ai-companion.eval.targets', []))
            ->map(fn (string $class): ?EvalTarget => rescue(
                fn (): ?EvalTarget => ($instance = app($class)) instanceof EvalTarget ? $instance : null,
                rescue: null,
                report: false,
            ))
            ->filter()
            ->values();

        if ($targets->isEmpty()) {
            error('No eval targets configured. Add EvalTarget classes to ai-companion.eval.targets.');

            return self::FAILURE;
        }

        $target = $this->resolveTarget($targets);

        if ($target === null) {
            return self::FAILURE;
        }

        $jsScorers = collect($target->scorers())->filter(fn (object $scorer): bool => $scorer instanceof JsScorer)->values();

        if ($jsScorers->isEmpty()) {
            error("Target [{$target->key()}] has no JS scorers. Only JS scorers can be published — PHP scorers cannot run in Braintrust.");

            return self::FAILURE;
        }

        $selected = $this->resolveScorers($jsScorers);

        if ($selected === null) {
            return self::FAILURE;
        }

        if ($selected->isEmpty()) {
            warning('Nothing selected — nothing published. Your scorers stay local.');

            return self::SUCCESS;
        }

        $sample = max(0.0, min(1.0, (float) ($this->option('sample') ?? text(
            label: 'What fraction of live traffic should be scored? (0.0–1.0)',
            default: '1.0',
            required: true,
            hint: 'Every scored span runs each published scorer — sample down when traffic or scorer cost is high.',
        ))));

        try {
            $ids = $selected->map(function (JsScorer $scorer) use ($api): string {
                $slug = Str::slug($scorer->name(), '-');
                $id = $api->upsertFunction($slug, Str::headline($scorer->name()), $scorer->code());

                // Smoke test in the REAL sandbox — local Node can diverge from it.
                $api->invokeFunction($id, ['output' => ['text' => 'smoke test'], 'input' => []]);
                info("{$scorer->name()}: synced and sandbox smoke test passed.");

                return $id;
            });

            $api->upsertOnlineRule(
                name: "{$target->key()} (online)",
                scorerIds: $ids->all(),
                spanNames: [Str::studly($target->key())],
                samplingRate: $sample,
                description: sprintf(
                    'Scores live %s spans with %s for "%s". Published from the app repo by ai:publish-eval — edit the JS there and re-publish, not here.',
                    Str::studly($target->key()),
                    $selected->map(fn (JsScorer $scorer): string => $scorer->name())->implode(', '),
                    $target->label(),
                ),
            );
        } catch (Throwable $exception) {
            error('Publish aborted — '.$exception->getMessage());

            return self::FAILURE;
        }

        outro(sprintf(
            'Online rule "%s (online)" is live: %d scorer(s) at %.0f%% sampling. New %s spans are scored in Braintrust from now on.',
            $target->key(),
            $ids->count(),
            $sample * 100,
            Str::studly($target->key()),
        ));

        return self::SUCCESS;
    }

    /** @param Collection<int, EvalTarget> $targets */
    private function resolveTarget(Collection $targets): ?EvalTarget
    {
        $only = $this->option('target');

        if ($only !== null) {
            $target = $targets->first(fn (EvalTarget $target): bool => $target->key() === $only);

            if ($target === null) {
                error("Unknown eval target [{$only}]. Available: ".$targets->map(fn (EvalTarget $t): string => $t->key())->implode(', '));
            }

            return $target;
        }

        $class = (string) select(
            label: 'Which eval do you want to publish for online scoring?',
            options: $targets->mapWithKeys(fn (EvalTarget $target): array => [$target::class => "{$target->label()} ({$target->key()})"])->all(),
            hint: 'Publishing pushes the eval\'s JS scorers to Braintrust to score live traffic.',
        );

        return $targets->first(fn (EvalTarget $target): bool => $target::class === $class);
    }

    /**
     * @param  Collection<int, JsScorer>  $jsScorers
     * @return Collection<int, JsScorer>|null null on invalid --scorers input
     */
    private function resolveScorers(Collection $jsScorers): ?Collection
    {
        $flag = $this->option('scorers');

        if ($flag === null) {
            $picked = multiselect(
                label: 'Which scorers should run against live traffic?',
                options: $jsScorers->mapWithKeys(fn (JsScorer $scorer): array => [$scorer->name() => $scorer->name().' (JS)'])->all(),
                hint: 'Only ticked scorers are pushed to Braintrust. Everything else stays local.',
            );

            return $jsScorers->filter(fn (JsScorer $scorer): bool => in_array($scorer->name(), (array) $picked, true))->values();
        }

        $names = collect(explode(',', (string) $flag))->map(fn (string $name): string => trim($name))->filter()->values();
        $unknown = $names->reject(fn (string $name): bool => $jsScorers->contains(fn (JsScorer $s): bool => $s->name() === $name));

        if ($unknown->isNotEmpty()) {
            error('Unknown scorer(s): '.$unknown->implode(', ').'. Available: '.$jsScorers->map(fn (JsScorer $s): string => $s->name())->implode(', '));

            return null;
        }

        return $jsScorers->filter(fn (JsScorer $scorer): bool => $names->contains($scorer->name()))->values();
    }
}

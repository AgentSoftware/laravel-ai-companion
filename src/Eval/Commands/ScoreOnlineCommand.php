<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Commands;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Online\OnlineSpanScorer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scores recent production spans with each registered target's local PHP
 * scorers and merges the scores back onto the Braintrust spans. Registered
 * via ai-companion.eval.online.targets; normally run from the scheduler.
 */
class ScoreOnlineCommand extends Command
{
    protected $signature = 'ai:score-online {--target= : Only score this eval target key} {--lookback= : Minutes of traffic to consider (default from config)}';

    protected $description = 'Run local eval scorers against recent production spans in Braintrust';

    public function handle(OnlineSpanScorer $scorer): int
    {
        $registered = collect((array) config('ai-companion.eval.online.targets', []));

        if ($registered->isEmpty()) {
            $this->warn('No online scoring targets registered (ai-companion.eval.online.targets).');

            return self::SUCCESS;
        }

        $lookback = (int) ($this->option('lookback') ?? config('ai-companion.eval.online.lookback_minutes', 60));
        $only = $this->option('target');

        $results = $registered
            ->map(fn (float|int $rate, string $class): ?array => rescue(
                fn (): array => ['target' => app($class), 'rate' => (float) $rate],
                rescue: null,
                report: false,
            ))
            ->filter(fn (?array $entry): bool => $entry !== null && $entry['target'] instanceof EvalTarget)
            ->filter(fn (array $entry): bool => $only === null || $entry['target']->key() === $only)
            ->values();

        if ($only !== null && $results->isEmpty()) {
            $this->error("Unknown online scoring target [{$only}].");

            return self::FAILURE;
        }

        $results->each(function (array $entry) use ($scorer, $lookback): void {
            try {
                $count = $scorer->score($entry['target'], $entry['rate'], $lookback);
                $this->info("{$entry['target']->key()}: scored {$count} span(s).");
            } catch (Throwable $exception) {
                $this->warn("{$entry['target']->key()}: failed — {$exception->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}

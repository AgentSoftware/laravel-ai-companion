<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Console;

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Evaluation\EvaluationRunner;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\CriterionResult;
use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;

class EvaluateCommand extends Command
{
    protected $signature = 'ai:evaluate
        {--agent= : Agent class name to evaluate (skips interactive picker)}
        {--since= : Only evaluate logs created after this point (e.g. 7d, 2026-06-01)}
        {--limit=50 : Maximum number of logs to evaluate per agent}
        {--re-run : Re-evaluate logs that already have a score}';

    protected $description = 'Evaluate AI agent responses using an LLM judge';

    public function handle(EvaluationRunner $runner): int
    {
        if (! config('ai-companion.evaluation.enabled', true)) {
            $this->components->warn('AI evaluation is disabled (AI_EVALUATION_ENABLED=false).');

            return self::SUCCESS;
        }

        $agents = $this->resolveAgents();

        if ($agents === []) {
            $this->components->warn('No agents found in ai_response_logs.');

            return self::SUCCESS;
        }

        collect($agents)->each(fn (string $agent) => $this->evaluateAgent($runner, $agent));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveAgents(): array
    {
        if ($agent = $this->option('agent')) {
            return [(string) $agent];
        }

        $available = AiResponseLog::query()
            ->select('agent')
            ->distinct()
            ->orderBy('agent')
            ->pluck('agent');

        if ($available->isEmpty()) {
            return [];
        }

        /** @var list<string> $selected */
        $selected = multiselect(
            label: 'Which agents would you like to evaluate?',
            options: $available->mapWithKeys(fn (string $a) => [$a => $a])->all(),
            required: true,
        );

        return $selected;
    }

    private function evaluateAgent(EvaluationRunner $runner, string $agent): void
    {
        $query = AiResponseLog::query()
            ->where('agent', $agent)
            ->where('status', AiResponseStatus::Success);

        if (! $this->option('re-run')) {
            $query->doesntHave('evaluations');
        }

        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', $this->parseSince((string) $since));
        }

        $logs = $query->limit((int) $this->option('limit'))->get();

        if ($logs->isEmpty()) {
            $this->components->info("No unevaluated logs found for {$agent}.");

            return;
        }

        $this->components->info("Evaluating {$agent} ({$logs->count()} logs)...");
        $this->newLine();

        [$succeeded, $failed] = $logs
            ->map(fn (AiResponseLog $log) => ['log' => $log, 'result' => $runner->run($log)])
            ->each(function (array $item): void {
                ['log' => $log, 'result' => $result] = $item;

                if ($result === null) {
                    $this->line(" <fg=red>✗</>  log {$this->shortId($log->id)}  FAILED — judge error, skipped");

                    return;
                }

                $criteriaLine = collect($result->criteria)
                    ->map(fn (CriterionResult $c): string => "{$c->name}:{$c->score}")
                    ->implode('  ');

                $this->line(" <fg=green>✓</>  log {$this->shortId($log->id)}  overall: {$result->overallScore}   {$criteriaLine}");
            })
            ->partition(fn (array $item) => $item['result'] !== null);

        $this->newLine();
        $this->printSummary($agent, $succeeded->count(), $failed->count(), (int) $this->option('limit'));
    }

    private function printSummary(string $agent, int $evaluated, int $skipped, int $limit): void
    {
        $evaluations = AiEvaluation::query()
            ->where('agent', $agent)
            ->latest()
            ->limit($limit)
            ->get();

        if ($evaluations->isEmpty()) {
            return;
        }

        $avg = (int) round((float) $evaluations->avg('overall_score'));

        $this->components->info('Summary');
        $this->line("  {$agent}   avg: {$avg}/100   {$evaluated} evaluated".($skipped > 0 ? ", {$skipped} skipped" : ''));

        $allCriteria = $evaluations
            ->flatMap(fn ($e) => $e->criteria)
            ->groupBy('name')
            ->map(fn ($group) => (int) round((float) $group->avg('score')));

        if ($allCriteria->isNotEmpty()) {
            $weakest = $allCriteria->sort()->keys()->first();
            $weakestScore = $allCriteria[$weakest];
            $this->line("  Lowest criterion: {$weakest} (avg {$weakestScore}) — consider reviewing the relevant part of the prompt.");
        }

        $this->newLine();
    }

    private function parseSince(string $since): Carbon
    {
        if (preg_match('/^(\d+)d$/', $since, $matches)) {
            return now()->subDays((int) $matches[1]);
        }

        return Carbon::parse($since);
    }

    private function shortId(string $uuid): string
    {
        return substr($uuid, 0, 8);
    }
}

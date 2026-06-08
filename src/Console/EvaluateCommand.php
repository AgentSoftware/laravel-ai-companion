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
use Illuminate\Database\Eloquent\Collection;

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
        $agents = $this->resolveAgents();

        if ($agents === []) {
            $this->components->warn('No agents found in ai_response_logs.');

            return self::SUCCESS;
        }

        foreach ($agents as $agent) {
            $this->evaluateAgent($runner, $agent);
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveAgents(): array
    {
        if ($agent = $this->option('agent')) {
            return [(string) $agent];
        }

        /** @var list<string> $available */
        $available = AiResponseLog::query()
            ->select('agent')
            ->distinct()
            ->orderBy('agent')
            ->pluck('agent')
            ->all();

        if ($available === []) {
            return [];
        }

        /** @var list<string> $selected */
        $selected = multiselect(
            label: 'Which agents would you like to evaluate?',
            options: array_combine($available, $available),
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

        /** @var Collection<int, AiResponseLog> $logs */
        $logs = $query->limit((int) $this->option('limit'))->get();

        if ($logs->isEmpty()) {
            $this->components->info("No unevaluated logs found for {$agent}.");

            return;
        }

        $this->components->info("Evaluating {$agent} ({$logs->count()} logs)...");
        $this->newLine();

        $evaluated = 0;
        $skipped = 0;

        foreach ($logs as $log) {
            $result = $runner->run($log);

            if ($result === null) {
                $this->line(" <fg=red>✗</>  log {$this->shortId($log->id)}  FAILED — judge error, skipped");
                $skipped++;
                continue;
            }

            $criteriaLine = collect($result->criteria)
                ->map(fn (CriterionResult $c): string => "{$c->name}:{$c->score}")
                ->implode('  ');

            $this->line(" <fg=green>✓</>  log {$this->shortId($log->id)}  overall: {$result->overallScore}   {$criteriaLine}");
            $evaluated++;
        }

        $this->newLine();
        $this->printSummary($agent, $evaluated, $skipped);
    }

    private function printSummary(string $agent, int $evaluated, int $skipped): void
    {
        $evaluations = AiEvaluation::query()
            ->where('agent', $agent)
            ->latest()
            ->limit(50)
            ->get();

        if ($evaluations->isEmpty()) {
            return;
        }

        $avg = (int) round((float) $evaluations->avg('overall_score'));

        $this->components->info('Summary');
        $this->line("  {$agent}   avg: {$avg}/100   {$evaluated} evaluated" . ($skipped > 0 ? ", {$skipped} skipped" : ''));

        $allCriteria = $evaluations
            ->flatMap(fn ($e) => $e->criteria)
            ->groupBy('name')
            ->map(fn ($group) => (int) round((float) $group->avg('score')));

        if ($allCriteria->isNotEmpty()) {
            $weakest = $allCriteria->sortBy(fn ($v) => $v)->keys()->first();
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

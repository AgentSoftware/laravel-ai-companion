<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Http\Controllers;

use AgentSoftware\LaravelAiCompanion\Evaluation\EvaluationRunner;
use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $agents = AiEvaluation::query()
            ->selectRaw('agent, COUNT(*) as total, AVG(overall_score) as avg_score')
            ->groupBy('agent')
            ->orderByDesc('avg_score')
            ->get();

        $logCounts = AiResponseLog::query()
            ->selectRaw('agent, COUNT(*) as total')
            ->groupBy('agent')
            ->pluck('total', 'agent');

        return view('ai-companion::index', compact('agents', 'logCounts'));
    }

    public function agent(string $agent): View
    {
        $agentName = base64_decode($agent);

        $logs = AiResponseLog::query()
            ->where('agent', $agentName)
            ->with('evaluations')
            ->orderByDesc('created_at')
            ->paginate(25);

        $stats = AiEvaluation::query()
            ->where('agent', $agentName)
            ->selectRaw('COUNT(*) as total, AVG(overall_score) as avg_score, MIN(overall_score) as min_score, MAX(overall_score) as max_score')
            ->first();

        return view('ai-companion::agent', compact('agentName', 'logs', 'stats'));
    }

    public function show(AiEvaluation $evaluation): View
    {
        $evaluation->load('log');

        return view('ai-companion::evaluation', compact('evaluation'));
    }

    public function insights(): View
    {
        $agents = AiEvaluation::query()
            ->selectRaw('agent, COUNT(*) as total_evals, AVG(overall_score) as avg_score')
            ->groupBy('agent')
            ->orderByDesc('avg_score')
            ->get();

        $logCounts = AiResponseLog::query()
            ->selectRaw('agent, COUNT(*) as total')
            ->groupBy('agent')
            ->pluck('total', 'agent');

        $totalEvals = $agents->sum('total_evals');
        $totalLogs  = $logCounts->sum();
        $avgAll     = $agents->where('avg_score', '>', 0)->avg('avg_score');

        // Trend: avg score per day for last 8 days
        $trend = AiEvaluation::query()
            ->selectRaw('DATE(created_at) as date, ROUND(AVG(overall_score)) as avg_score')
            ->where('created_at', '>=', now()->subDays(8))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Weakest criterion across all evaluations
        $criteriaScores = AiEvaluation::all()
            ->flatMap(fn ($e) => collect($e->criteria)->map(fn ($c) => ['name' => $c['name'], 'score' => $c['score']]))
            ->groupBy('name')
            ->map(fn ($g) => (int) round($g->avg('score')))
            ->sortBy(fn ($v) => $v);

        $weakestCriterion = $criteriaScores->keys()->first();
        $weakestScore     = $criteriaScores->first();

        return view('ai-companion::insights', compact(
            'agents', 'logCounts', 'totalEvals', 'totalLogs', 'avgAll',
            'trend', 'weakestCriterion', 'weakestScore', 'criteriaScores'
        ));
    }

    public function evaluateLog(AiResponseLog $log, EvaluationRunner $runner): JsonResponse
    {
        $result = $runner->run($log);

        if ($result === null) {
            return response()->json(['error' => 'Evaluation failed — judge returned an invalid response.'], 422);
        }

        $evaluation = AiEvaluation::where('ai_response_log_id', $log->id)->latest()->first();

        return response()->json([
            'redirect' => route('ai-companion.evaluation', $evaluation->id),
        ]);
    }
}

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

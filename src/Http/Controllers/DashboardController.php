<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Http\Controllers;

use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
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

        return view('ai-companion::index', compact('agents'));
    }

    public function agent(string $agent): View
    {
        $agentName = base64_decode($agent);

        $evaluations = AiEvaluation::query()
            ->where('agent', $agentName)
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('ai-companion::agent', compact('agentName', 'evaluations'));
    }

    public function show(AiEvaluation $evaluation): View
    {
        $evaluation->load('log');

        return view('ai-companion::evaluation', compact('evaluation'));
    }
}

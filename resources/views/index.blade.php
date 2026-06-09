@extends('ai-companion::layout')

@section('content')
@php
    function scoreband(int|null $score): string {
        if ($score === null) return 'none';
        if ($score >= 85) return 'green';
        if ($score >= 70) return 'amber';
        if ($score >= 55) return 'orange';
        return 'red';
    }

    function verdictLabel(int|null $score): string {
        if ($score === null) return '—';
        if ($score >= 85) return 'Strong';
        if ($score >= 70) return 'Acceptable';
        if ($score >= 55) return 'Needs work';
        return 'Failing';
    }

    $allAgents     = $logCounts->keys()->merge($agents->pluck('agent'))->unique()->sort()->values();
    $evalsByAgent  = $agents->keyBy('agent');
    $totalLogs     = $logCounts->sum();
    $totalEvals    = $agents->sum('total');
    $awaitingEval  = $totalLogs - $totalEvals;
    $evalPct       = $totalLogs > 0 ? min(100, round(($totalEvals / $totalLogs) * 100)) : 0;
    $avgAll        = $agents->where('avg_score', '>', 0)->avg('avg_score');
    $avgScore      = $avgAll ? (int) round($avgAll) : null;
    $agentCount    = $allAgents->count();

    $iconMap = [
        'ContentWriter' => 'ph-pencil-simple',
        'Research'      => 'ph-magnifying-glass',
        'Navigation'    => 'ph-compass',
        'Refinement'    => 'ph-funnel',
    ];
@endphp

<div class="page-head fade-in">
    <h1 class="page-head__title">Agents</h1>
    <p class="page-head__sub">Overview of all agents with response logs</p>
</div>

{{-- Stat band --}}
<div class="statband fade-in">
    <div class="stat">
        <div class="stat__header">
            <span class="stat__label">Agents</span>
            <span class="stat__icon"><i class="ph ph-robot"></i></span>
        </div>
        <div class="stat__value">{{ $agentCount }}</div>
        <div class="stat__footer">distinct agents tracked</div>
    </div>

    <div class="stat">
        <div class="stat__header">
            <span class="stat__label">Response Logs</span>
            <span class="stat__icon"><i class="ph ph-stack"></i></span>
        </div>
        <div class="stat__value">{{ number_format($totalLogs) }}</div>
        <div class="stat__footer">{{ number_format($awaitingEval) }} awaiting evaluation</div>
    </div>

    <div class="stat">
        <div class="stat__header">
            <span class="stat__label">Evaluated</span>
            <span class="stat__icon"><i class="ph ph-check-circle"></i></span>
        </div>
        <div class="stat__value">{{ number_format($totalEvals) }}</div>
        <div class="stat__footer">{{ $evalPct }}% of all logs</div>
        <div class="stat__progress">
            <div class="stat__progress-fill" style="width: {{ $evalPct }}%"></div>
        </div>
    </div>

    <div class="stat sc-{{ scoreband($avgScore) }}">
        <div class="stat__header">
            <span class="stat__label">Avg Score</span>
            <span class="stat__icon"><i class="ph ph-gauge"></i></span>
        </div>
        <div class="stat__value" style="color: var(--sc, var(--color-default-900))">
            {{ $avgScore !== null ? $avgScore : '—' }}
        </div>
        <div class="stat__footer">{{ verdictLabel($avgScore) }}</div>
    </div>
</div>

{{-- Toolbar --}}
<div x-data="{
    search: '',
    filter: 'all',
    agents: {{ Js::from($allAgents->mapWithKeys(function($a) use ($evalsByAgent, $logCounts) {
        $evalRow = $evalsByAgent->get($a);
        $total = $logCounts->get($a, 0);
        $evals = $evalRow?->total ?? 0;
        return [$a => ['total' => $total, 'evals' => $evals]];
    })) }}
}">
    <div class="toolbar">
        <input
            type="search"
            class="search-input"
            placeholder="Search agents…"
            x-model="search"
        >
        <div class="seg-control">
            <button class="seg-btn" :class="{ active: filter === 'all' }" @click="filter = 'all'">All</button>
            <button class="seg-btn" :class="{ active: filter === 'evaluated' }" @click="filter = 'evaluated'">Evaluated</button>
            <button class="seg-btn" :class="{ active: filter === 'pending' }" @click="filter = 'pending'">Pending</button>
        </div>
    </div>

    {{-- Agent table --}}
    @if($allAgents->isEmpty())
        <div class="card fade-in" style="text-align:center; padding: 48px 20px; color: var(--color-fg-secondary)">
            <i class="ph ph-robot" style="font-size:40px; display:block; margin-bottom:12px; opacity:.4"></i>
            <div style="font-weight:600; margin-bottom:6px">No data yet</div>
            <div style="font-size:13px">AI response logs will appear here once agents start running.</div>
        </div>
    @else
        <div class="card fade-in">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Logs</th>
                        <th>Evaluations</th>
                        <th>Status</th>
                        <th>Avg Score</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($allAgents as $agentClass)
                        @php
                            $parts      = explode('\\', $agentClass);
                            $shortName  = end($parts);
                            $evalRow    = $evalsByAgent->get($agentClass);
                            $avgScore   = $evalRow ? (int) round($evalRow->avg_score) : null;
                            $band       = scoreband($avgScore);
                            $totalLogs  = $logCounts->get($agentClass, 0);
                            $totalEvals = $evalRow?->total ?? 0;
                            $unevaluated = max(0, $totalLogs - $totalEvals);
                            $evPct      = $totalLogs > 0 ? min(100, round(($totalEvals / $totalLogs) * 100)) : 0;
                            $allEval    = $totalLogs > 0 && $unevaluated === 0;
                            $iconKey    = array_key_exists($shortName, $iconMap) ? $iconMap[$shortName] : 'ph-robot';
                        @endphp
                        <tr
                            x-show="
                                (search === '' || '{{ strtolower($agentClass) }}'.includes(search.toLowerCase())) &&
                                (filter === 'all' ||
                                 (filter === 'evaluated' && {{ $allEval ? 'true' : 'false' }}) ||
                                 (filter === 'pending' && {{ !$allEval ? 'true' : 'false' }}))
                            "
                            onclick="window.location='{{ route('ai-companion.agent', base64_encode($agentClass)) }}'"
                        >
                            <td>
                                <div class="agent-cell">
                                    <div class="agent-icon">
                                        <i class="ph {{ $iconKey }}"></i>
                                    </div>
                                    <div>
                                        <div class="agent-name">{{ $shortName }}</div>
                                        <div class="agent-path">{{ $agentClass }}</div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:13px; color:var(--color-default-700)">
                                {{ number_format($totalLogs) }}
                            </td>
                            <td>
                                <div class="score-bar">
                                    <div class="score-bar__track">
                                        <div class="score-bar__fill" style="width:{{ $evPct }}%; background: var(--color-primary-500)"></div>
                                    </div>
                                    <span style="font-size:12px; color:var(--color-default-500); white-space:nowrap">
                                        {{ number_format($totalEvals) }}/{{ number_format($totalLogs) }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                @if($totalLogs === 0)
                                    <span class="chip pending"><span class="chip-dot"></span>No logs</span>
                                @elseif($allEval)
                                    <span class="chip evaluated"><span class="chip-dot"></span>Evaluated</span>
                                @else
                                    <span class="chip pending"><span class="chip-dot"></span>Pending</span>
                                @endif
                            </td>
                            <td>
                                @if($avgScore !== null)
                                    <div class="score-bar sc-{{ $band }}">
                                        <div class="score-bar__track">
                                            <div class="score-bar__fill" style="width:{{ $avgScore }}%"></div>
                                        </div>
                                        <span class="score-bar__num">{{ $avgScore }}</span>
                                    </div>
                                @else
                                    <span style="font-size:12px; color:var(--color-default-300)">—</span>
                                @endif
                            </td>
                            <td style="text-align:right; padding-right:18px">
                                <i class="ph ph-caret-right chevron"></i>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

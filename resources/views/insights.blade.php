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

    $avgScoreInt  = $avgAll ? (int) round($avgAll) : null;
    $avgBand      = scoreband($avgScoreInt);
    $pendingLogs  = $totalLogs - $totalEvals;

    $iconMap = [
        'ContentWriter' => 'ph-pencil-simple',
        'Research'      => 'ph-magnifying-glass',
        'Navigation'    => 'ph-compass',
        'Refinement'    => 'ph-funnel',
    ];

    // Backlog = agents sorted by (total_logs - total_evals) desc, top 4
    $backlog = $logCounts
        ->map(function($total, $agent) use ($agents) {
            $evalRow = $agents->firstWhere('agent', $agent);
            $evals = $evalRow?->total_evals ?? 0;
            return ['agent' => $agent, 'pending' => max(0, $total - $evals)];
        })
        ->sortByDesc('pending')
        ->take(4)
        ->values();

    // Coverage donut
    $covPct    = $totalLogs > 0 ? min(1, $totalEvals / $totalLogs) : 0;
    $covSize   = 150;
    $covStroke = 18;
    $covR      = ($covSize - $covStroke) / 2;
    $covC      = 2 * M_PI * $covR;
    $covOff    = $covC * (1 - $covPct);
    $covPctInt = (int) round($covPct * 100);

    // Criteria count
    $criteriaCount = $criteriaScores->count();

    // Trend chart dimensions
    $chartW = 720; $chartH = 220;
    $padL = 36; $padR = 16; $padT = 16; $padB = 36;
    $plotW = $chartW - $padL - $padR;
    $plotH = $chartH - $padT - $padB;
    $trendCount = $trend->count();
@endphp

<div class="page-head fade-in">
    <h1 class="page-head__title">Insights</h1>
    <p class="page-head__sub">Aggregate evaluation analytics across all agents</p>
</div>

{{-- Stat band --}}
<div class="statband fade-in">
    <div class="stat">
        <div class="stat__header">
            <span class="stat__label">Evaluations run</span>
            <span class="stat__icon"><i class="ph ph-check-circle"></i></span>
        </div>
        <div class="stat__value">{{ number_format($totalEvals) }}</div>
        <div class="stat__footer">{{ number_format($pendingLogs) }} still pending</div>
    </div>

    <div class="stat sc-{{ $avgBand }}">
        <div class="stat__header">
            <span class="stat__label">Avg Score</span>
            <span class="stat__icon"><i class="ph ph-gauge"></i></span>
        </div>
        <div class="stat__value" style="color: var(--sc, var(--color-default-900))">
            {{ $avgScoreInt !== null ? $avgScoreInt : '—' }}
        </div>
        <div class="stat__footer">{{ verdictLabel($avgScoreInt) }}</div>
    </div>

    <div class="stat sc-orange">
        <div class="stat__header">
            <span class="stat__label">Weakest criterion</span>
            <span class="stat__icon" style="background:var(--color-orange-50); color:var(--color-orange-600)"><i class="ph ph-warning"></i></span>
        </div>
        <div class="stat__value" style="font-size:18px; color:var(--color-orange-600); font-weight:700; margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px">
            {{ $weakestCriterion ?? '—' }}
        </div>
        <div class="stat__footer" style="color:var(--color-orange-600)">
            averaging {{ $weakestScore ?? 0 }}/100
        </div>
    </div>

    <div class="stat">
        <div class="stat__header">
            <span class="stat__label">Criteria tracked</span>
            <span class="stat__icon"><i class="ph ph-list-checks"></i></span>
        </div>
        <div class="stat__value">{{ $criteriaCount }}</div>
        <div class="stat__footer">distinct criteria names</div>
    </div>
</div>

{{-- Middle grid: agent performance (2/3) + coverage (1/3) --}}
<div class="ins-grid fade-in">
    {{-- Agent performance leaderboard --}}
    <div class="card">
        <div class="card__header">
            <div>
                <div class="card__title">Agent Performance</div>
                <div class="card__sub">Sorted by avg score descending</div>
            </div>
        </div>
        <div class="card__body" style="padding-top:8px; padding-bottom:8px">
            @forelse($agents as $agentRow)
                @php
                    $parts     = explode('\\', $agentRow->agent);
                    $short     = end($parts);
                    $s         = (int) round($agentRow->avg_score);
                    $b         = scoreband($s);
                    $ik        = array_key_exists($short, $iconMap) ? $iconMap[$short] : 'ph-robot';
                @endphp
                <a href="{{ route('ai-companion.agent', base64_encode($agentRow->agent)) }}"
                   class="lb-row" style="text-decoration:none">
                    <div class="lb-icon">
                        <i class="ph {{ $ik }}"></i>
                    </div>
                    <div class="lb-info">
                        <div class="lb-name">{{ $short }}</div>
                        <div class="lb-path">{{ $agentRow->agent }}</div>
                    </div>
                    <div class="score-bar sc-{{ $b }}" style="width:160px">
                        <div class="score-bar__track">
                            <div class="score-bar__fill" style="width:{{ $s }}%"></div>
                        </div>
                        <span class="score-bar__num">{{ $s }}</span>
                    </div>
                </a>
            @empty
                <div style="padding:20px; text-align:center; color:var(--color-fg-secondary); font-size:13px">
                    No evaluations yet.
                </div>
            @endforelse
        </div>
    </div>

    {{-- Evaluation coverage donut --}}
    <div class="card">
        <div class="card__header">
            <div class="card__title">Evaluation Coverage</div>
        </div>
        <div class="card__body" style="display:flex; flex-direction:column; align-items:center">
            @php
                $ringColor = 'var(--color-primary-600)';
                $ringTrack = 'var(--color-default-100)';
            @endphp
            <div class="ring" style="width:{{ $covSize }}px; height:{{ $covSize }}px">
                <svg width="{{ $covSize }}" height="{{ $covSize }}" style="transform:rotate(-90deg); position:absolute; top:0; left:0">
                    <circle cx="{{ $covSize/2 }}" cy="{{ $covSize/2 }}" r="{{ $covR }}"
                            fill="none" stroke="{{ $ringTrack }}" stroke-width="{{ $covStroke }}"/>
                    <circle cx="{{ $covSize/2 }}" cy="{{ $covSize/2 }}" r="{{ $covR }}"
                            fill="none" stroke="{{ $ringColor }}" stroke-width="{{ $covStroke }}"
                            stroke-linecap="round"
                            stroke-dasharray="{{ $covC }}"
                            stroke-dashoffset="{{ $covOff }}"/>
                </svg>
                <div class="ring__label" style="position:relative; z-index:1">
                    <span class="ring__num" style="font-size:32px">{{ $covPctInt }}%</span>
                    <span class="ring__out">covered</span>
                </div>
            </div>

            <div class="cov-legend">
                <div class="cov-legend__item">
                    <div class="cov-legend__dot" style="background: var(--color-primary-600)"></div>
                    Evaluated {{ number_format($totalEvals) }}
                </div>
                <div class="cov-legend__item">
                    <div class="cov-legend__dot" style="background: var(--color-default-200)"></div>
                    Pending {{ number_format($pendingLogs) }}
                </div>
            </div>

            @if($backlog->isNotEmpty())
                <div class="cov-backlog" style="width:100%">
                    <div class="cov-backlog__title">Biggest backlogs</div>
                    @foreach($backlog as $bl)
                        @php
                            $blParts = explode('\\', $bl['agent']);
                            $blShort = end($blParts);
                        @endphp
                        <div class="cov-backlog__row">
                            <span class="cov-backlog__name">{{ $blShort }}</span>
                            <span class="cov-backlog__count">{{ $bl['pending'] }} pending</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Trend chart --}}
<div class="card fade-in" style="margin-bottom:14px">
    <div class="card__header">
        <div class="card__title">Average Score Trend</div>
        <div class="card__sub">Last 8 days</div>
    </div>
    <div class="card__body trend-chart" style="padding: 0 8px 16px">
        @if($trend->isEmpty())
            <div style="padding:48px 20px; text-align:center; color:var(--color-fg-secondary); font-size:13px">
                <i class="ph ph-chart-line-up" style="font-size:28px; display:block; margin-bottom:8px; opacity:.35"></i>
                No evaluation history yet
            </div>
        @else
            @php
                $trendPts = $trend->map(function($t, $i) use ($trendCount, $padL, $padR, $padT, $padB, $plotW, $plotH, $chartW, $chartH) {
                    $x = $padL + ($trendCount > 1 ? ($i / ($trendCount - 1)) : 0.5) * $plotW;
                    $y = $padT + (1 - ($t->avg_score / 100)) * $plotH;
                    return ['x' => round($x, 1), 'y' => round($y, 1), 'score' => $t->avg_score, 'date' => $t->date];
                });

                $polyline = $trendPts->map(fn($p) => "{$p['x']},{$p['y']}")->implode(' ');
                $areaPath = "M {$trendPts->first()['x']} " . ($padT + $plotH) .
                            " L {$trendPts->first()['x']} {$trendPts->first()['y']} " .
                            $trendPts->slice(1)->map(fn($p) => "L {$p['x']} {$p['y']}")->implode(' ') .
                            " L {$trendPts->last()['x']} " . ($padT + $plotH) . " Z";
                $gridLines = [0, 25, 50, 75, 100];
            @endphp

            <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" style="width:100%; height:auto; display:block">
                <defs>
                    <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--color-primary-500)" stop-opacity="0.18"/>
                        <stop offset="100%" stop-color="var(--color-primary-500)" stop-opacity="0.01"/>
                    </linearGradient>
                </defs>

                {{-- Grid lines --}}
                @foreach($gridLines as $gv)
                    @php
                        $gy = $padT + (1 - $gv / 100) * $plotH;
                    @endphp
                    <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $chartW - $padR }}" y2="{{ $gy }}"
                          stroke="var(--color-default-100)" stroke-width="1"/>
                    <text x="{{ $padL - 6 }}" y="{{ $gy + 4 }}"
                          text-anchor="end" font-size="10" fill="var(--color-default-400)">{{ $gv }}</text>
                @endforeach

                {{-- Area fill --}}
                <path d="{{ $areaPath }}" fill="url(#areaGrad)"/>

                {{-- Line --}}
                <polyline points="{{ $polyline }}"
                          fill="none"
                          stroke="var(--color-primary-500)"
                          stroke-width="2.5"
                          stroke-linejoin="round"
                          stroke-linecap="round"/>

                {{-- Dots + labels --}}
                @foreach($trendPts as $pt)
                    <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="4"
                            fill="var(--color-primary-500)" stroke="#fff" stroke-width="2"/>
                    <text x="{{ $pt['x'] }}" y="{{ $padT + $plotH + 20 }}"
                          text-anchor="middle" font-size="10" fill="var(--color-default-400)">
                        {{ \Carbon\Carbon::parse($pt['date'])->format('M j') }}
                    </text>
                @endforeach
            </svg>
        @endif
    </div>
</div>

{{-- Criteria breakdown --}}
@if($criteriaScores->isNotEmpty())
    <div class="card fade-in">
        <div class="card__header">
            <div>
                <div class="card__title">Criteria Breakdown</div>
                <div class="card__sub">Average score per criterion, weakest first</div>
            </div>
        </div>
        <div class="card__body">
            <div class="critchart">
                @foreach($criteriaScores as $critName => $critScore)
                    @php $cb = scoreband($critScore); @endphp
                    <div class="critchart__row sc-{{ $cb }}">
                        <div class="critchart__label" title="{{ $critName }}">{{ $critName }}</div>
                        <div class="critchart__track">
                            <div class="critchart__fill" style="width:{{ $critScore }}%"></div>
                        </div>
                        <div class="critchart__num">{{ $critScore }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

@endsection

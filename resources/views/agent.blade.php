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

    $parts     = explode('\\', $agentName);
    $shortName = end($parts);
    $avgScore  = $stats && $stats->total > 0 ? (int) round($stats->avg_score) : null;
    $bestScore = $stats && $stats->total > 0 ? (int) $stats->max_score : null;
    $worstScore= $stats && $stats->total > 0 ? (int) $stats->min_score : null;
    $avgBand   = scoreband($avgScore);
@endphp

<div class="page-head fade-in">
    <a href="{{ route('ai-companion.index') }}" class="page-head__back">
        <i class="ph ph-arrow-left"></i> All Agents
    </a>
    <h1 class="page-head__title">{{ $shortName }}</h1>
    <p class="page-head__sub" style="font-family:monospace">{{ $agentName }}</p>
</div>

{{-- Stat band --}}
<div class="statband fade-in">
    <div class="stat">
        <div class="stat__header">
            <span class="stat__label">Response Logs</span>
            <span class="stat__icon"><i class="ph ph-stack"></i></span>
        </div>
        <div class="stat__value">{{ number_format($logs->total()) }}</div>
        <div class="stat__footer">total logged interactions</div>
    </div>

    <div class="stat sc-{{ $avgBand }}">
        <div class="stat__header">
            <span class="stat__label">Avg Score</span>
            <span class="stat__icon"><i class="ph ph-gauge"></i></span>
        </div>
        <div class="stat__value" style="color: var(--sc, var(--color-default-900))">
            {{ $avgScore !== null ? $avgScore : '—' }}
        </div>
        <div class="stat__footer">across {{ number_format($stats?->total ?? 0) }} evaluations</div>
    </div>

    <div class="stat sc-{{ scoreband($bestScore) }}">
        <div class="stat__header">
            <span class="stat__label">Best</span>
            <span class="stat__icon"><i class="ph ph-trophy"></i></span>
        </div>
        <div class="stat__value" style="color: var(--sc, var(--color-default-900))">
            {{ $bestScore !== null ? $bestScore : '—' }}
        </div>
        <div class="stat__footer">highest evaluation score</div>
    </div>

    <div class="stat sc-{{ scoreband($worstScore) }}">
        <div class="stat__header">
            <span class="stat__label">Worst</span>
            <span class="stat__icon"><i class="ph ph-warning"></i></span>
        </div>
        <div class="stat__value" style="color: var(--sc, var(--color-default-900))">
            {{ $worstScore !== null ? $worstScore : '—' }}
        </div>
        <div class="stat__footer">lowest evaluation score</div>
    </div>
</div>

{{-- Response logs card --}}
<div class="card fade-in">
    <div class="card__header">
        <div>
            <div class="card__title">Response Logs</div>
            <div class="card__sub">{{ number_format($logs->total()) }} total &mdash; click Evaluate to score any entry</div>
        </div>
    </div>

    @if($logs->isEmpty())
        <div style="padding: 40px 20px; text-align:center; color:var(--color-fg-secondary)">
            No logs found for this agent.
        </div>
    @else
        <table class="tbl">
            <thead>
                <tr>
                    <th>Log</th>
                    <th>Prompt preview</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Score</th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                    @php
                        $evaluation   = $log->evaluations->last();
                        $promptRaw    = is_array($log->prompt)
                            ? collect($log->prompt)->map(fn($v) => is_string($v) ? $v : json_encode($v))->implode(' ')
                            : (string) $log->prompt;
                        $promptPreview = strip_tags($promptRaw);
                        $s = $evaluation?->overall_score;
                        $band = $s !== null ? (
                            $s >= 85 ? 'green' : ($s >= 70 ? 'amber' : ($s >= 55 ? 'orange' : 'red'))
                        ) : 'none';
                    @endphp
                    <tr style="cursor:default">
                        <td>
                            <span class="logid">{{ substr($log->id, 0, 8) }}</span>
                        </td>
                        <td>
                            <div class="prompt-preview" title="{{ e($promptPreview) }}">{{ $promptPreview }}</div>
                        </td>
                        <td>
                            @if($evaluation)
                                <span class="chip evaluated"><span class="chip-dot"></span>Evaluated</span>
                            @else
                                <span class="chip pending"><span class="chip-dot"></span>Pending</span>
                            @endif
                        </td>
                        <td style="font-size:13px; color:var(--color-fg-secondary); white-space:nowrap">
                            {{ $log->created_at->format('M j, Y H:i') }}
                        </td>
                        <td>
                            @if($evaluation)
                                <a href="{{ route('ai-companion.evaluation', $evaluation->id) }}"
                                   class="score-badge sc-{{ $band }}"
                                   onclick="event.stopPropagation()"
                                   style="text-decoration:none">
                                    {{ $s }}
                                    <i class="ph ph-arrow-right" style="font-size:11px"></i>
                                </a>
                            @else
                                <div x-data="{ loading: false, error: null }">
                                    <button
                                        x-on:click="
                                            loading = true; error = null;
                                            fetch('{{ route('ai-companion.log.evaluate', $log->id) }}', {
                                                method: 'POST',
                                                headers: {
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                    'Accept': 'application/json',
                                                }
                                            })
                                            .then(r => r.json())
                                            .then(data => {
                                                if (data.redirect) window.location.href = data.redirect;
                                                else { error = data.error ?? 'Unknown error'; loading = false; }
                                            })
                                            .catch(() => { error = 'Request failed'; loading = false; })
                                        "
                                        :disabled="loading"
                                        class="btn primary sm"
                                    >
                                        <span x-show="loading" class="spin"></span>
                                        <i x-show="!loading" class="ph ph-play"></i>
                                        <span x-text="loading ? 'Evaluating…' : 'Evaluate'"></span>
                                    </button>
                                    <p x-show="error" x-text="error"
                                       style="font-size:11px; color:var(--color-red-600); margin:4px 0 0"></p>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($logs->hasPages())
            <div style="padding: 12px 16px; border-top: 1px solid var(--color-default-100)">
                {{ $logs->links() }}
            </div>
        @endif
    @endif
</div>
@endsection

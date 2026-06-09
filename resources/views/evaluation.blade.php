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

    $agentParts    = explode('\\', $evaluation->agent);
    $shortAgentName = end($agentParts);
    $score         = $evaluation->overall_score;
    $scoreBand     = scoreband($score);
    $verdict       = verdictLabel($score);

    // Sort criteria weakest first
    $sortedCriteria = collect($evaluation->criteria)->sortBy('score')->values();
    $criteriaCount  = $sortedCriteria->count();

    // Donut ring
    $size   = 108;
    $stroke = 11;
    $r      = ($size - $stroke) / 2;
    $c      = 2 * M_PI * $r;
    $pct    = max(0, min(100, $score)) / 100;
    $off    = $c * (1 - $pct);
@endphp

<div class="page-head fade-in">
    <a href="{{ route('ai-companion.agent', base64_encode($evaluation->agent)) }}"
       class="page-head__back">
        <i class="ph ph-arrow-left"></i> Back to {{ $shortAgentName }}
    </a>
</div>

{{-- Eval hero --}}
<div class="eval-hero sc-{{ $scoreBand }} fade-in">
    <div class="eval-hero__left">
        <div class="eval-hero__verdict">
            <div class="eval-hero__verdict-dot"></div>
            {{ $verdict }}
        </div>
        <h2 class="eval-hero__title">Evaluation detail</h2>
        <div class="eval-hero__meta">
            <span>{{ $evaluation->created_at->format('M j, Y H:i:s') }}</span>
            <span style="color:var(--color-default-200)">·</span>
            <span class="judge-pill">
                <i class="ph ph-cpu" style="font-size:11px"></i>
                {{ $evaluation->judge_model }}
            </span>
            @if($evaluation->scorer)
                <span style="color:var(--color-default-200)">·</span>
                <span class="judge-pill" style="background:var(--color-default-100); color:var(--color-default-600)">
                    {{ $evaluation->scorer }}
                </span>
            @endif
        </div>
    </div>

    {{-- SVG Donut ring --}}
    <div class="ring sc-{{ $scoreBand }}" style="width:{{ $size }}px; height:{{ $size }}px">
        <svg width="{{ $size }}" height="{{ $size }}" style="transform:rotate(-90deg)">
            <circle class="ring__track"
                    cx="{{ $size/2 }}" cy="{{ $size/2 }}" r="{{ $r }}"
                    fill="none" stroke-width="{{ $stroke }}"/>
            <circle class="ring__fill"
                    cx="{{ $size/2 }}" cy="{{ $size/2 }}" r="{{ $r }}"
                    fill="none" stroke-width="{{ $stroke }}"
                    stroke-dasharray="{{ $c }}"
                    stroke-dashoffset="{{ $off }}"/>
        </svg>
        <div class="ring__label">
            <span class="ring__num" style="font-size:30px">{{ $score }}</span>
            <span class="ring__out">/ 100</span>
        </div>
    </div>
</div>

{{-- Summary card --}}
<div class="card fade-in" style="margin-bottom:14px">
    <div class="card__header">
        <div class="card__title">Summary</div>
    </div>
    <div class="card__body">
        <p class="summary-text">{{ $evaluation->summary }}</p>
    </div>
</div>

{{-- Criteria scores card --}}
<div class="card fade-in" style="margin-bottom:14px">
    <div class="card__header">
        <div>
            <div class="card__title">Criteria Scores</div>
            <div class="card__sub">{{ $criteriaCount }} {{ Str::plural('criterion', $criteriaCount) }} &mdash; sorted weakest first</div>
        </div>
    </div>

    @foreach($sortedCriteria as $criterion)
        @php
            $cs      = $criterion['score'];
            $cBand   = scoreband($cs);
        @endphp
        <div class="criterion sc-{{ $cBand }}">
            <div class="criterion__name">{{ $criterion['name'] }}</div>
            <div class="score-bar" style="max-width:150px">
                <div class="score-bar__track">
                    <div class="score-bar__fill" style="width:{{ $cs }}%"></div>
                </div>
                <span class="score-bar__num">{{ $cs }}</span>
            </div>
            <div class="criterion__fb">{{ $criterion['feedback'] }}</div>
        </div>
    @endforeach
</div>

{{-- Prompt card (collapsible) --}}
@if($evaluation->log)
    <div class="card fade-in" x-data="{ open: false }">
        <div class="prompt-toggle" @click="open = !open">
            <div class="card__title">Prompt</div>
            <i class="ph ph-caret-down" :style="open ? 'transform:rotate(180deg)' : ''"></i>
        </div>
        <div class="prompt-body" x-show="open" x-transition>
            <pre>{{ is_array($evaluation->log->prompt)
                ? json_encode($evaluation->log->prompt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : $evaluation->log->prompt }}</pre>
        </div>
    </div>
@endif
@endsection

@extends('ai-companion::layout')

@section('content')
    @php
        $agentParts = explode('\\', $evaluation->agent);
        $shortAgentName = end($agentParts);
        $score = $evaluation->overall_score;
        $scoreColorText = $score >= 80 ? 'text-green-600' : ($score >= 60 ? 'text-yellow-600' : 'text-red-600');
        $scoreColorBadge = $score >= 80 ? 'bg-green-100 text-green-800' : ($score >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
    @endphp

    <div class="mb-6">
        <a href="{{ route('ai-companion.agent', base64_encode($evaluation->agent)) }}"
           class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4">
            <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to {{ $shortAgentName }}
        </a>

        <div class="flex items-start justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-900">Evaluation Detail</h2>
                <p class="text-sm text-gray-500 mt-1">{{ $evaluation->created_at->format('M j, Y H:i:s') }}</p>
            </div>
            <div class="text-right">
                <div class="text-5xl font-bold {{ $scoreColorText }}">{{ $score }}</div>
                <div class="text-sm text-gray-500 mt-1">out of 100</div>
            </div>
        </div>
    </div>

    <!-- Summary -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-3">Summary</h3>
        <p class="text-gray-700 leading-relaxed">{{ $evaluation->summary }}</p>

        <div class="mt-4 flex items-center gap-3">
            <span class="text-xs text-gray-500">Judge model:</span>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                {{ $evaluation->judge_model }}
            </span>
            @if($evaluation->scorer)
                <span class="text-xs text-gray-500">Scorer:</span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 font-mono">
                    {{ $evaluation->scorer }}
                </span>
            @endif
        </div>
    </div>

    <!-- Criteria -->
    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Criteria Scores</h3>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Criterion</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Score</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Feedback</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($evaluation->criteria as $criterion)
                    @php
                        $cScore = $criterion['score'];
                        $barColor = $cScore >= 80 ? 'bg-green-500' : ($cScore >= 60 ? 'bg-yellow-500' : 'bg-red-500');
                        $badgeColor = $cScore >= 80 ? 'text-green-700' : ($cScore >= 60 ? 'text-yellow-700' : 'text-red-700');
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            {{ $criterion['name'] }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <div class="flex-1 bg-gray-200 rounded-full h-2 w-32">
                                    <div class="{{ $barColor }} h-2 rounded-full" style="width: {{ $cScore }}%"></div>
                                </div>
                                <span class="text-sm font-semibold {{ $badgeColor }} w-12 text-right">{{ $cScore }}/100</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            {{ $criterion['feedback'] }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Prompt collapsible -->
    @if($evaluation->log)
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <details>
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between hover:bg-gray-50 transition-colors">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Prompt</h3>
                    <svg class="h-5 w-5 text-gray-400 details-chevron transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </summary>
                <div class="px-6 py-4 border-t border-gray-200">
                    <pre class="text-xs text-gray-700 bg-gray-50 rounded p-4 overflow-x-auto whitespace-pre-wrap font-mono">{{ is_array($evaluation->log->prompt) ? json_encode($evaluation->log->prompt, JSON_PRETTY_PRINT) : $evaluation->log->prompt }}</pre>
                </div>
            </details>
        </div>
    @endif
@endsection

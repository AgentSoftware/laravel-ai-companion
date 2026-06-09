@extends('ai-companion::layout')

@section('content')
    @php
        $parts = explode('\\', $agentName);
        $shortName = end($parts);
        $avgScore = $stats && $stats->total > 0 ? (int) round($stats->avg_score) : null;
        $scoreColor = $avgScore === null ? '' : ($avgScore >= 80 ? 'text-green-600' : ($avgScore >= 60 ? 'text-yellow-600' : 'text-red-600'));
    @endphp

    {{-- Header --}}
    <div class="mb-8">
        <a href="{{ route('ai-companion.index') }}"
           class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-3">
            <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            All Agents
        </a>
        <h2 class="text-2xl font-bold text-gray-900">{{ $shortName }}</h2>
        <p class="text-sm text-gray-400 font-mono mt-0.5">{{ $agentName }}</p>
    </div>

    {{-- Stats row --}}
    @if($stats && $stats->total > 0)
        <div class="grid grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Evaluations</p>
                <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($stats->total) }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Score</p>
                <p class="text-3xl font-bold {{ $scoreColor }} mt-1">{{ $avgScore }}<span class="text-lg text-gray-400">/100</span></p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Best</p>
                <p class="text-3xl font-bold text-green-600 mt-1">{{ $stats->max_score }}<span class="text-lg text-gray-400">/100</span></p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Worst</p>
                <p class="text-3xl font-bold text-red-500 mt-1">{{ $stats->min_score }}<span class="text-lg text-gray-400">/100</span></p>
            </div>
        </div>
    @endif

    {{-- Response logs --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Response Logs</h3>
                <p class="text-xs text-gray-400 mt-0.5">{{ $logs->total() }} total &mdash; click Evaluate to score any entry</p>
            </div>
        </div>

        @if($logs->isEmpty())
            <div class="p-12 text-center text-gray-400">No logs found for this agent.</div>
        @else
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Log</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prompt preview</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-44">Score</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($logs as $log)
                        @php
                            $evaluation = $log->evaluations->last();
                            $promptPreview = is_array($log->prompt)
                                ? collect($log->prompt)->map(fn($v) => is_string($v) ? $v : json_encode($v))->implode(' ')
                                : (string) $log->prompt;
                            $promptPreview = Str::limit(strip_tags($promptPreview), 80);
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-mono text-xs text-gray-500">{{ substr($log->id, 0, 8) }}</span>
                            </td>
                            <td class="px-6 py-4 max-w-xs">
                                <span class="text-sm text-gray-600 truncate block">{{ $promptPreview }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $log->created_at->format('M j, Y H:i') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($evaluation)
                                    @php
                                        $s = $evaluation->overall_score;
                                        $sc = $s >= 80 ? 'bg-green-100 text-green-800' : ($s >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                                    @endphp
                                    <a href="{{ route('ai-companion.evaluation', $evaluation->id) }}"
                                       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold {{ $sc }} hover:opacity-80 transition-opacity">
                                        {{ $s }}/100 &rarr;
                                    </a>
                                @else
                                    <div x-data="{ loading: false, error: null }"
                                         x-on:submit.prevent>
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
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-medium bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed transition-colors">
                                            <svg x-show="loading" class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <span x-text="loading ? 'Evaluating…' : 'Evaluate'"></span>
                                        </button>
                                        <p x-show="error" x-text="error" class="text-xs text-red-500 mt-1"></p>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-4 border-t border-gray-100">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
@endsection

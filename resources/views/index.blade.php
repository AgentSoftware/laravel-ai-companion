@extends('ai-companion::layout')

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Agents</h2>
        <p class="text-sm text-gray-500 mt-1">Overview of all agents with response logs</p>
    </div>

    @if($agents->isEmpty() && $logCounts->isEmpty())
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            <h3 class="mt-4 text-lg font-medium text-gray-900">No data yet</h3>
            <p class="mt-2 text-sm text-gray-500">AI response logs will appear here once agents start running.</p>
        </div>
    @else
        @php
            $allAgents = $logCounts->keys()->merge($agents->pluck('agent'))->unique()->sort()->values();
            $evalsByAgent = $agents->keyBy('agent');
        @endphp
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Agent</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Logs</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Evaluations</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Score</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($allAgents as $agentClass)
                        @php
                            $parts = explode('\\', $agentClass);
                            $shortName = end($parts);
                            $evalRow = $evalsByAgent->get($agentClass);
                            $avgScore = $evalRow ? (int) round($evalRow->avg_score) : null;
                            $scoreColor = $avgScore === null ? '' : ($avgScore >= 80 ? 'bg-green-100 text-green-800' : ($avgScore >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'));
                            $totalLogs = $logCounts->get($agentClass, 0);
                            $totalEvals = $evalRow?->total ?? 0;
                            $unevaluated = $totalLogs - $totalEvals;
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="{{ route('ai-companion.agent', base64_encode($agentClass)) }}"
                                   class="text-indigo-600 hover:text-indigo-900 font-medium">
                                    {{ $shortName }}
                                </a>
                                <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $agentClass }}</p>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                {{ number_format($totalLogs) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="text-gray-700">{{ number_format($totalEvals) }}</span>
                                @if($unevaluated > 0)
                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">
                                        {{ $unevaluated }} pending
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($avgScore !== null)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $scoreColor }}">
                                        {{ $avgScore }}/100
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection

@extends('ai-companion::layout')

@section('content')
    <div class="mb-6">
        <a href="{{ route('ai-companion.index') }}"
           class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4">
            <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Dashboard
        </a>
        <h2 class="text-2xl font-bold text-gray-900">
            @php
                $parts = explode('\\', $agentName);
                $shortName = end($parts);
            @endphp
            {{ $shortName }}
        </h2>
        <p class="text-sm text-gray-500 mt-1 font-mono">{{ $agentName }}</p>
    </div>

    @if($evaluations->isEmpty())
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <h3 class="text-lg font-medium text-gray-900">No evaluations found</h3>
            <p class="mt-2 text-sm text-gray-500">No evaluations exist for this agent yet.</p>
        </div>
    @else
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Overall Score</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Top Criteria</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($evaluations as $evaluation)
                        @php
                            $score = $evaluation->overall_score;
                            $scoreColor = $score >= 80 ? 'bg-green-100 text-green-800' : ($score >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                            $topCriteria = collect($evaluation->criteria)->take(3);
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                {{ $evaluation->created_at->format('M j, Y H:i') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $scoreColor }}">
                                    {{ $score }}/100
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1">
                                    @foreach($topCriteria as $criterion)
                                        @php
                                            $cScore = $criterion['score'];
                                            $cColor = $cScore >= 80 ? 'bg-green-50 text-green-700 border-green-200' : ($cScore >= 60 ? 'bg-yellow-50 text-yellow-700 border-yellow-200' : 'bg-red-50 text-red-700 border-red-200');
                                        @endphp
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs border {{ $cColor }}">
                                            {{ $criterion['name'] }}: {{ $cScore }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <a href="{{ route('ai-companion.evaluation', $evaluation->id) }}"
                                   class="text-indigo-600 hover:text-indigo-900 font-medium">
                                    View &rarr;
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $evaluations->links() }}
        </div>
    @endif
@endsection

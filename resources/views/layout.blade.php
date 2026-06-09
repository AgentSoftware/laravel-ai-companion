<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Companion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-gray-900 text-white flex flex-col">
            <div class="px-6 py-5 border-b border-gray-700">
                <h1 class="text-xl font-bold tracking-tight text-white">AI Companion</h1>
                <p class="text-xs text-gray-400 mt-1">Evaluation Dashboard</p>
            </div>
            <nav class="flex-1 px-4 py-6 space-y-1">
                <a href="{{ route('ai-companion.index') }}"
                   class="flex items-center px-3 py-2 text-sm font-medium rounded-md text-gray-300 hover:bg-gray-800 hover:text-white transition-colors {{ request()->routeIs('ai-companion.index') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="mr-3 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    Dashboard
                </a>
            </nav>
            <div class="px-4 py-4 border-t border-gray-700">
                <p class="text-xs text-gray-500">agentsoftware/laravel-ai-companion</p>
            </div>
        </aside>

        <!-- Main content -->
        <main class="flex-1 overflow-y-auto">
            <div class="p-8">
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>

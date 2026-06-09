<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::allows('viewAiCompanion')) {
            abort(403);
        }

        return $next($request);
    }
}

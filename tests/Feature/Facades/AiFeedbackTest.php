<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Facades\AiFeedback;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai-companion.braintrust.enabled', true);
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'My Project');
});

it('records feedback through the facade', function () {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/feedback' => Http::response(['status' => 'success']),
    ]);

    AiFeedback::record('App\Models\OnboardingSession', 'session-9', good: true, comment: 'Nice');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/feedback')
        && $request->data()['feedback'][0]['comment'] === 'Nice');
});

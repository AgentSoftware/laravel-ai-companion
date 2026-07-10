<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Exceptions\BraintrustFeedbackException;
use AgentSoftware\LaravelAiCompanion\Feedback\BraintrustFeedbackClient;
use AgentSoftware\LaravelAiCompanion\Tracing\SpanBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai-companion.braintrust.enabled', true);
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'My Project');
});

function fakeBraintrustFeedbackApi(): void
{
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/feedback' => Http::response(['status' => 'success']),
    ]);
}

it('posts feedback for the deterministic root span id of the given source', function () {
    fakeBraintrustFeedbackApi();

    $expectedId = SpanBuilder::rootSpanId('App\Models\OnboardingSession', 'session-9');

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: true, comment: 'Great result');

    Http::assertSent(function (Request $request) use ($expectedId): bool {
        if (! str_contains($request->url(), '/v1/project_logs/proj-123/feedback')) {
            return false;
        }

        $feedback = $request->data()['feedback'][0];

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && $feedback['id'] === $expectedId
            && $feedback['scores'] === ['user_feedback' => 1.0]
            && $feedback['comment'] === 'Great result'
            && $feedback['source'] === 'app';
    });
});

it('maps a bad rating to a zero score and omits a null comment', function () {
    fakeBraintrustFeedbackApi();

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: false);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/feedback')) {
            return false;
        }

        $feedback = $request->data()['feedback'][0];

        return $feedback['scores'] === ['user_feedback' => 0.0]
            && ! array_key_exists('comment', $feedback);
    });
});

it('resolves and caches the project id across repeated calls', function () {
    fakeBraintrustFeedbackApi();

    $client = app(BraintrustFeedbackClient::class);
    $client->record('App\Models\OnboardingSession', 'session-9', good: true);
    $client->record('App\Models\OnboardingSession', 'session-9', good: true);

    Http::assertSentCount(3); // 1 project resolution + 2 feedback posts
});

it('throws when braintrust is disabled', function () {
    config()->set('ai-companion.braintrust.enabled', false);

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: true);
})->throws(BraintrustFeedbackException::class);

it('throws when no api key is configured', function () {
    config()->set('ai-companion.braintrust.api_key', null);

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: true);
})->throws(BraintrustFeedbackException::class);

it('throws when the http request fails', function () {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/feedback' => Http::response(status: 500),
    ]);

    app(BraintrustFeedbackClient::class)->record('App\Models\OnboardingSession', 'session-9', good: true);
})->throws(BraintrustFeedbackException::class);

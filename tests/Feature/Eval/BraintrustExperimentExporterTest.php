<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\EvalRunMetadata;
use AgentSoftware\LaravelAiCompanion\Eval\EvalRunMetrics;
use AgentSoftware\LaravelAiCompanion\Eval\ExperimentEventData;
use AgentSoftware\LaravelAiCompanion\Eval\Exporters\BraintrustExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\RepoInfo;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'My Project');
});

function fakeBraintrustExperimentApi(): void
{
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/experiment' => Http::response(['id' => 'exp-9']),
        'api.braintrust.dev/v1/experiment/exp-9/insert' => Http::response(['row_ids' => ['1']]),
    ]);
}

/**
 * @param  array<int, Score>|null  $scores
 */
function experimentEvent(?array $scores = null): ExperimentEventData
{
    return new ExperimentEventData(
        input: ['brief' => 'Make it pop'],
        output: ['blocks' => []],
        scores: $scores ?? [new Score('catalogue_valid', 1.0), new Score('hydrates_clean', 0.5)],
        metadata: new EvalRunMetadata(promptName: null, promptVersion: 3, model: null, provider: null, tags: ['terse']),
        metrics: new EvalRunMetrics(latencyMs: 1200, promptTokens: 600, completionTokens: 300, tokens: 900),
    );
}

it('is bound as the ExperimentExporter implementation', function () {
    expect(app(ExperimentExporter::class))->toBeInstanceOf(BraintrustExperimentExporter::class);
});

it('is enabled whenever an api key is present, regardless of the tracing flag', function () {
    config()->set('ai-companion.braintrust.enabled', false);
    expect(app(BraintrustExperimentExporter::class)->enabled())->toBeTrue();

    config()->set('ai-companion.braintrust.api_key', null);
    expect(app(BraintrustExperimentExporter::class)->enabled())->toBeFalse();
});

it('creates a fresh named experiment and inserts scored events into it', function () {
    fakeBraintrustExperimentApi();

    $id = app(BraintrustExperimentExporter::class)->export('composer/v3/gemini/t0', [experimentEvent()]);

    expect($id)->toBe('exp-9');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/experiment')
        && $request->data() === ['project_id' => 'proj-123', 'name' => 'composer/v3/gemini/t0', 'ensure_new' => true]);
});

it('attaches git repo info so the backend can auto-select a baseline', function () {
    fakeBraintrustExperimentApi();

    app(BraintrustExperimentExporter::class)->export('composer/v3/gemini/t0', [experimentEvent()], [], new RepoInfo(
        branch: 'feature/spd-2432',
        commit: 'abc123',
    ));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/experiment')
        && $request->data()['repo_info'] === ['branch' => 'feature/spd-2432', 'commit' => 'abc123']
        && $request->data()['ensure_new'] === true);
});

it('attaches experiment-level metadata such as a catalogue snapshot', function () {
    fakeBraintrustExperimentApi();

    app(BraintrustExperimentExporter::class)->export('composer/v3/gemini/t0', [experimentEvent()], [
        'catalogue_ids' => ['hero/a', 'body/b'],
    ]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/experiment')
        && $request->data() === [
            'project_id' => 'proj-123',
            'name' => 'composer/v3/gemini/t0',
            'ensure_new' => true,
            'metadata' => ['catalogue_ids' => ['hero/a', 'body/b']],
        ]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/v1/experiment/exp-9/insert')) {
            return false;
        }

        $event = $request->data()['events'][0];

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && $event['scores'] === ['catalogue_valid' => 1.0, 'hydrates_clean' => 0.5]
            && $event['input'] === ['brief' => 'Make it pop']
            && $event['metadata'] === ['prompt_version' => 3, 'tags' => ['terse']]
            && $event['metrics'] === ['latency_ms' => 1200, 'prompt_tokens' => 600, 'completion_tokens' => 300, 'tokens' => 900];
    });
});

it('folds per-score diagnostics into the event metadata so they are not lost', function () {
    fakeBraintrustExperimentApi();

    $event = experimentEvent([new Score('summarises_brief', 0.8, ['reasoning' => 'captures the topic'])]);

    app(BraintrustExperimentExporter::class)->export('composer/v3/gemini/t0', [$event]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/insert')) {
            return false;
        }

        $event = $request->data()['events'][0];

        return $event['scores'] === ['summarises_brief' => 0.8]
            && $event['metadata']['scores'] === ['summarises_brief' => ['reasoning' => 'captures the topic']];
    });
});

it('throws on http failure', function () {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/experiment' => Http::response(status: 500),
    ]);

    app(BraintrustExperimentExporter::class)->export('composer/v3/gemini/t0', [experimentEvent()]);
})->throws(RequestException::class);

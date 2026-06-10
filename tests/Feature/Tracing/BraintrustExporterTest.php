<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Exporters\BraintrustExporter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('ai-companion.braintrust.enabled', true);
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'My Project');
});

function fakeBraintrustApi(): void
{
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/insert' => Http::response(['row_ids' => ['1']]),
    ]);
}

function neutralSpan(): array
{
    return [
        'id' => 'inv-1',
        'trace_id' => 'root-1',
        'parent_id' => 'root-1',
        'name' => 'ContentWriterAgent',
        'type' => 'llm',
        'input' => ['prompt' => 'Hello'],
        'output' => 'World',
        'error' => null,
        'metadata' => ['model' => 'claude-haiku-4-5-20251001'],
        'metrics' => ['start' => 1.0, 'end' => 2.0, 'prompt_tokens' => 10, 'completion_tokens' => 5, 'tokens' => 15, 'cache_write_tokens' => null, 'cache_read_tokens' => 0, 'reasoning_tokens' => 0],
    ];
}

it('is bound as the TraceExporter implementation', function () {
    expect(app(TraceExporter::class))->toBeInstanceOf(BraintrustExporter::class);
});

it('is enabled only with the flag and an api key', function () {
    expect(app(BraintrustExporter::class)->enabled())->toBeTrue();

    config()->set('ai-companion.braintrust.api_key', null);
    expect(app(BraintrustExporter::class)->enabled())->toBeFalse();

    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.enabled', false);
    expect(app(BraintrustExporter::class)->enabled())->toBeFalse();
});

it('ships spans mapped to braintrust insert events', function () {
    fakeBraintrustApi();

    app(BraintrustExporter::class)->ship([neutralSpan()]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/v1/project_logs/proj-123/insert')) {
            return false;
        }

        $event = $request->data()['events'][0];

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && $event['id'] === 'inv-1'
            && $event['span_id'] === 'inv-1'
            && $event['root_span_id'] === 'root-1'
            && $event['span_parents'] === ['root-1']
            && $event['span_attributes'] === ['name' => 'ContentWriterAgent', 'type' => 'llm']
            && $event['metrics'] === ['start' => 1.0, 'end' => 2.0, 'prompt_tokens' => 10, 'completion_tokens' => 5, 'tokens' => 15, 'cache_read_tokens' => 0, 'reasoning_tokens' => 0]
            && ! array_key_exists('error', $event);
    });
});

it('omits span_parents for root spans', function () {
    fakeBraintrustApi();

    $root = neutralSpan();
    $root['id'] = 'root-1';
    $root['parent_id'] = null;

    app(BraintrustExporter::class)->ship([$root]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/insert')) {
            return false;
        }

        return ! array_key_exists('span_parents', $request->data()['events'][0]);
    });
});

it('resolves the project id once and caches it', function () {
    fakeBraintrustApi();

    $exporter = app(BraintrustExporter::class);
    $exporter->ship([neutralSpan()]);
    $exporter->ship([neutralSpan()]);

    Http::assertSentCount(3); // 1 project resolution + 2 inserts

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/project')
        && $request->data() === ['name' => 'My Project']);
});

it('preserves an empty input array for no-argument tool calls', function () {
    fakeBraintrustApi();

    $span = neutralSpan();
    $span['type'] = 'tool';
    $span['input'] = [];

    app(BraintrustExporter::class)->ship([$span]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/v1/project_logs/proj-123/insert')) {
            return false;
        }

        $event = $request->data()['events'][0];

        return array_key_exists('input', $event) && $event['input'] === [];
    });
});

it('omits empty metrics and metadata that would serialize as json arrays', function () {
    fakeBraintrustApi();

    // Root spans ship with no metrics; PHP's empty array encodes to json []
    // but Braintrust requires these fields to be objects (or absent).
    $root = neutralSpan();
    $root['id'] = 'root-1';
    $root['parent_id'] = null;
    $root['metadata'] = [];
    $root['metrics'] = [];

    app(BraintrustExporter::class)->ship([$root]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/v1/project_logs/proj-123/insert')) {
            return false;
        }

        $event = $request->data()['events'][0];

        return ! array_key_exists('metrics', $event)
            && ! array_key_exists('metadata', $event);
    });
});

it('omits metrics when every value is null', function () {
    fakeBraintrustApi();

    $span = neutralSpan();
    $span['metrics'] = ['start' => null, 'end' => null];

    app(BraintrustExporter::class)->ship([$span]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/v1/project_logs/proj-123/insert')) {
            return false;
        }

        return ! array_key_exists('metrics', $request->data()['events'][0]);
    });
});

it('throws on http failure so the queued job retries', function () {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-123']),
        'api.braintrust.dev/v1/project_logs/proj-123/insert' => Http::response(status: 500),
    ]);

    app(BraintrustExporter::class)->ship([neutralSpan()]);
})->throws(RequestException::class);

it('chunks events into multiple requests to stay under the payload limit', function () {
    config()->set('ai-companion.braintrust.max_payload_bytes', 800);
    fakeBraintrustApi();

    app(BraintrustExporter::class)->ship([neutralSpan(), neutralSpan(), neutralSpan()]);

    // 1 project resolution + 2 chunked inserts (each event is ~362 bytes, two per chunk).
    Http::assertSentCount(3);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/insert')) {
            return true;
        }

        return strlen((string) json_encode($request->data()['events'])) <= 800;
    });
});

it('drops a single event larger than the payload limit and ships the rest', function () {
    config()->set('ai-companion.braintrust.max_payload_bytes', 700);
    fakeBraintrustApi();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'payload limit'));

    $oversized = neutralSpan();
    $oversized['id'] = 'huge-1';
    $oversized['output'] = str_repeat('x', 2000);

    app(BraintrustExporter::class)->ship([$oversized, neutralSpan()]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/insert')) {
            return true;
        }

        return collect($request->data()['events'])->pluck('id')->all() === ['inv-1'];
    });
});

it('sends no insert request when every event is dropped', function () {
    config()->set('ai-companion.braintrust.max_payload_bytes', 700);
    fakeBraintrustApi();

    Log::shouldReceive('warning')->once();

    $oversized = neutralSpan();
    $oversized['output'] = str_repeat('x', 2000);

    app(BraintrustExporter::class)->ship([$oversized]);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/insert'));
});

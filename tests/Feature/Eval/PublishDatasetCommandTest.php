<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::preventStrayRequests();
    File::ensureDirectoryExists(base_path('fixtures'));
    File::put(base_path('fixtures/epc.pdf'), '%PDF-1.4 epc');
    File::put(base_path('fixtures/epc-two.pdf'), '%PDF-1.4 two');
    File::put(base_path('fixtures/epc-dataset.json'), json_encode([
        ['id' => 'epc-1', 'brief' => 'read it', 'document' => ['type' => 'local_file', 'path' => 'fixtures/epc.pdf'], 'expected' => ['rating' => 'C'], 'tags' => ['epc']],
        ['id' => 'epc-2', 'brief' => 'read this too', 'document' => ['type' => 'local_file', 'path' => 'fixtures/epc-two.pdf']],
    ]));
});

afterEach(function (): void {
    File::deleteDirectory(base_path('fixtures'));
});

function fakeBraintrustDatasetPublishing(array $existingRowIds = [], int $putStatus = 200, string $signedHost = 'bucket.test'): void
{
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1', 'org_id' => 'org-1']),
        'api.braintrust.dev/v1/dataset' => Http::response(['id' => 'ds-1', 'name' => 'epc-dataset']),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::response(['events' => array_map(fn (string $id): array => ['id' => $id, 'input' => []], $existingRowIds)]),
        'api.braintrust.dev/v1/dataset/ds-1/insert' => Http::response(['row_ids' => ['1']]),
        'api.braintrust.dev/attachment' => fn (Request $request) => Http::response([
            'signedUrl' => "https://{$signedHost}/upload/".$request['key'],
            'headers' => ['Content-Type' => $request['content_type'], 'x-amz-meta-test' => 'yes'],
        ]),
        'api.braintrust.dev/attachment/status' => Http::response([]),
        "{$signedHost}/*" => Http::response('', $putStatus),
    ]);
}

it('uploads each fixture and inserts the rows with their attachment references', function (): void {
    fakeBraintrustDatasetPublishing();

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.braintrust.dev/v1/dataset'
        && $request['project_id'] === 'proj-1'
        && $request['name'] === 'epc-dataset');

    $uploads = Http::recorded(fn (Request $request): bool => $request->url() === 'https://api.braintrust.dev/attachment');
    expect($uploads)->toHaveCount(2);

    $first = $uploads->first()[0];
    expect($first['filename'])->toBe('epc.pdf')
        ->and($first['content_type'])->toBe('application/pdf')
        ->and($first['org_id'])->toBe('org-1')
        ->and($first['key'])->toBeString()->not->toBeEmpty();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://bucket.test/upload/'.$first['key']
        && $request->body() === '%PDF-1.4 epc'
        && $request->header('x-amz-meta-test') === ['yes']
        && $request->header('Content-Type') === ['application/pdf']
        && ! $request->hasHeader('Authorization'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.braintrust.dev/attachment/status'
        && $request['key'] === $first['key']
        && $request['org_id'] === 'org-1'
        && $request['status'] === ['upload_status' => 'done']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.braintrust.dev/v1/dataset/ds-1/insert'
        && $request['events'][0] === [
            'id' => 'epc-1',
            'input' => [
                'brief' => 'read it',
                'document' => ['type' => 'braintrust_attachment', 'filename' => 'epc.pdf', 'content_type' => 'application/pdf', 'key' => $first['key']],
            ],
            'expected' => ['rating' => 'C'],
            'tags' => ['epc'],
        ]
        && array_keys($request['events'][1]) === ['id', 'input']
        && $request['events'][1]['input']['document']['filename'] === 'epc-two.pdf');
});

it('uploads a fixture reference exactly like a local_file reference', function (): void {
    File::ensureDirectoryExists(base_path('resources/ai/fixtures'));
    File::put(base_path('resources/ai/fixtures/epc.pdf'), '%PDF-1.4 epc');
    File::put(base_path('fixtures/epc-dataset.json'), json_encode([[
        'id' => 'epc-1',
        'by_fixture' => ['type' => 'fixture', 'name' => 'epc.pdf'],
        'by_path' => ['type' => 'local_file', 'path' => 'resources/ai/fixtures/epc.pdf'],
    ]]));
    fakeBraintrustDatasetPublishing();

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])->assertSuccessful();

    $uploads = Http::recorded(fn (Request $request): bool => $request->url() === 'https://api.braintrust.dev/attachment');
    $keys = $uploads->map(fn (array $pair): string => $pair[0]['key'])->values();
    expect($uploads)->toHaveCount(2);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/insert')
        && $request['events'][0]['input'] === [
            'by_fixture' => ['type' => 'braintrust_attachment', 'filename' => 'epc.pdf', 'content_type' => 'application/pdf', 'key' => $keys[0]],
            'by_path' => ['type' => 'braintrust_attachment', 'filename' => 'epc.pdf', 'content_type' => 'application/pdf', 'key' => $keys[1]],
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->body() === '%PDF-1.4 epc');

    File::deleteDirectory(base_path('resources/ai'));
});

it('counts fixture references in a dry run', function (): void {
    File::ensureDirectoryExists(base_path('resources/ai/fixtures'));
    File::put(base_path('resources/ai/fixtures/epc.pdf'), '%PDF-1.4 epc');
    File::put(base_path('fixtures/epc-dataset.json'), json_encode([['id' => 'epc-1', 'document' => ['type' => 'fixture', 'name' => 'epc.pdf']]]));

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json', '--dry-run' => true])
        ->expectsOutputToContain('would publish 1 row(s) and upload 1 file(s)')
        ->assertSuccessful();

    File::deleteDirectory(base_path('resources/ai'));
});

it('publishes to the named dataset', function (): void {
    fakeBraintrustDatasetPublishing();

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json', '--name' => 'epc-readings'])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.braintrust.dev/v1/dataset' && $request['name'] === 'epc-readings');
});

it('skips rows the dataset already has, so a re-run uploads nothing twice', function (): void {
    fakeBraintrustDatasetPublishing(['epc-1']);

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])->assertSuccessful();

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://api.braintrust.dev/attachment'))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/insert')
        && count($request['events']) === 1
        && $request['events'][0]['id'] === 'epc-2');
});

it('does nothing when every row is already published', function (): void {
    fakeBraintrustDatasetPublishing(['epc-1', 'epc-2']);

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/attachment') || str_ends_with($request->url(), '/insert'));
});

it('replaces rows that already exist when asked to', function (): void {
    fakeBraintrustDatasetPublishing(['epc-1', 'epc-2']);

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json', '--replace' => true])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/insert') && count($request['events']) === 2);
});

it('gives a row without an id the same id on every run', function (): void {
    File::put(base_path('fixtures/epc-dataset.json'), json_encode([['brief' => 'no id', 'document' => ['type' => 'local_file', 'path' => 'fixtures/epc.pdf']]]));
    fakeBraintrustDatasetPublishing();

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])->assertSuccessful();
    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])->assertSuccessful();

    $ids = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/insert'))
        ->map(fn (array $pair): string => $pair[0]['events'][0]['id'])
        ->values();

    expect($ids)->toHaveCount(2)->and($ids[0])->toBe($ids[1]);
});

it('inserts nothing when an upload to the object store fails, and reports the failure to Braintrust', function (): void {
    fakeBraintrustDatasetPublishing(putStatus: 403);

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])
        ->expectsOutputToContain('Publish aborted — Braintrust attachment [epc.pdf] failed to upload to the object store.')
        ->assertFailed();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.braintrust.dev/attachment/status'
        && $request['status']['upload_status'] === 'error'
        && is_string($request['status']['error_message']));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/insert'));
});

it('refuses to upload to Azure-hosted Braintrust', function (): void {
    fakeBraintrustDatasetPublishing(signedHost: 'account.blob.core.windows.net');

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])
        ->expectsOutputToContain('uploads to Azure-hosted Braintrust are not supported')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

it('checks the fixtures and counts the rows on a dry run without calling Braintrust', function (): void {
    Http::fake();

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json', '--dry-run' => true])
        ->expectsOutputToContain('Dry run: would publish 2 row(s)')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('reports how many fixture files a dry run would upload', function (): void {
    Http::fake();

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json', '--dry-run' => true])
        ->expectsOutputToContain('upload 2 file(s)')
        ->assertSuccessful();
});

it('fails a dry run when a fixture file is missing', function (): void {
    File::delete(base_path('fixtures/epc-two.pdf'));

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json', '--dry-run' => true])
        ->expectsOutputToContain('Eval fixture file [fixtures/epc-two.pdf] does not exist.')
        ->assertFailed();
});

it('fails when the dataset file does not exist', function (): void {
    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/nope.json'])
        ->expectsOutputToContain('Dataset file [fixtures/nope.json] does not exist.')
        ->assertFailed();
});

it('fails when the dataset file has no rows', function (): void {
    File::put(base_path('fixtures/epc-dataset.json'), '[]');

    $this->artisan('ai:publish-dataset', ['path' => 'fixtures/epc-dataset.json'])
        ->expectsOutputToContain('Dataset file has no rows.')
        ->assertFailed();
});

it('sends a header-supplied content type once, in the case the API used', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1', 'org_id' => 'org-1']),
        'api.braintrust.dev/attachment' => Http::response(['signedUrl' => 'https://bucket.test/x', 'headers' => ['content-type' => 'application/octet-stream', 'x-amz-acl' => 'private']]),
        'api.braintrust.dev/attachment/status' => Http::response([]),
        'bucket.test/*' => Http::response(''),
    ]);

    new BraintrustApi()->uploadAttachment('epc.pdf', 'application/pdf', 'bytes');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->header('Content-Type') === ['application/octet-stream']
        && $request->header('x-amz-acl') === ['private']);
});

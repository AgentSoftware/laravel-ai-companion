<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\BraintrustAttachments;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\ClassifierStubTarget;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\RecordingConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubEvalCommand;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubHarness;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');
});

function braintrustPhotoReference(string $key = 'att-1', string $filename = 'meter.jpg', string $contentType = 'image/jpeg'): array
{
    return ['type' => 'braintrust_attachment', 'key' => $key, 'filename' => $filename, 'content_type' => $contentType];
}

function fakeBraintrustAttachmentApi(array $extra = []): void
{
    Http::fake([
        ...$extra,
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1', 'org_id' => 'org-1']),
        'api.braintrust.dev/attachment?*' => fn (Request $request) => Http::response([
            'downloadUrl' => 'https://bucket.test/'.$request['key'],
            'status' => ['upload_status' => 'done'],
        ]),
        'bucket.test/*' => fn (Request $request) => Http::response('bytes-of-'.basename($request->url())),
    ]);
}

it('loads every row of a named dataset across pages', function (): void {
    $events = array_map(fn (int $i): array => ['input' => ['report' => "row {$i}"], 'expected' => ['set' => 'must_catch'], 'tags' => null], range(1, 100));

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [['id' => 'ds-1']]]),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::sequence()
            ->push(['events' => $events, 'cursor' => 'page-2'])
            ->push(['events' => [
                ['input' => 'a plain prompt', 'expected' => null, 'tags' => ['keep']],
                ['input' => ['report' => 'own', 'expected' => 'mine'], 'expected' => 'theirs'],
            ], 'cursor' => 'page-3']),
    ]);

    $rows = new BraintrustApi()->datasetRows('maintenance-hazard-photos');

    expect($rows)->toHaveCount(102)
        ->and($rows[0])->toBe(['report' => 'row 1', 'expected' => ['set' => 'must_catch']])
        ->and($rows[100])->toBe(['input' => 'a plain prompt', 'tags' => ['keep']])
        ->and($rows[101])->toBe(['report' => 'own', 'expected' => 'mine']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'dataset_name=maintenance-hazard-photos')
        && str_contains($request->url(), 'project_id=proj-1'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/fetch') && ($request->data()['cursor'] ?? null) === 'page-2');
});

it('fails loudly when the named dataset does not exist', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => []]),
    ]);

    new BraintrustApi()->datasetRows('missing');
})->throws(RuntimeException::class, 'Braintrust dataset [missing] not found in the configured project.');

it('downloads an attachment through its presigned URL without sending the API key', function (): void {
    fakeBraintrustAttachmentApi();

    expect(new BraintrustApi()->attachment('att-1', 'meter.jpg', 'image/jpeg'))->toBe('bytes-of-att-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/attachment?')
        && $request['key'] === 'att-1'
        && $request['filename'] === 'meter.jpg'
        && $request['content_type'] === 'image/jpeg'
        && $request['org_id'] === 'org-1');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://bucket.test/att-1' && ! $request->hasHeader('Authorization'));
});

it('refuses an attachment that has not finished uploading', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1', 'org_id' => 'org-1']),
        'api.braintrust.dev/attachment?*' => Http::response(['downloadUrl' => 'https://bucket.test/x', 'status' => ['upload_status' => 'uploading']]),
    ]);

    new BraintrustApi()->attachment('att-1', 'meter.jpg', 'image/jpeg');
})->throws(RuntimeException::class, 'Braintrust attachment [meter.jpg] is not uploaded yet.');

it('replaces nested attachment references with files, downloading each one once', function (): void {
    fakeBraintrustAttachmentApi();

    $attachments = new BraintrustAttachments(new BraintrustApi);

    $first = $attachments->resolve(['report' => 'gas', 'photos' => [braintrustPhotoReference()], 'meta' => ['n' => 1]]);
    $second = $attachments->resolve(['photos' => [braintrustPhotoReference(), braintrustPhotoReference('att-2', 'lease.pdf', 'application/pdf')]]);

    expect($first['report'])->toBe('gas')
        ->and($first['meta'])->toBe(['n' => 1])
        ->and($first['photos'][0])->toBeInstanceOf(Base64Image::class)
        ->and($first['photos'][0]->content())->toBe('bytes-of-att-1')
        ->and($first['photos'][0]->mime)->toBe('image/jpeg')
        ->and($first['photos'][0]->name())->toBe('meter.jpg')
        ->and($second['photos'][1])->toBeInstanceOf(Base64Document::class)
        ->and($second['photos'][1]->content())->toBe('bytes-of-att-2');

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://bucket.test/att-1'))->toHaveCount(1);
});

it('runs a classifier eval over a Braintrust dataset with its photos attached', function (): void {
    config()->set('ai-companion.braintrust.api_key', null);
    config()->set('ai-companion.eval.harness', StubHarness::class);
    config()->set('ai-companion.eval.targets', [ClassifierStubTarget::class]);
    $this->app[Kernel::class]->registerCommand(new StubEvalCommand);
    $this->app->bind(ConcurrencyRunner::class, RecordingConcurrencyRunner::class);

    fakeBraintrustAttachmentApi([
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [['id' => 'ds-1']]]),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::response(['events' => [
            ['input' => ['decision' => 'hazard', 'state' => 'Smell of gas', 'attachments' => [braintrustPhotoReference()]], 'expected' => ['gas' => true]],
        ]]),
    ]);
    Classification::fake([['gas' => new BooleanAnswer(0.9)]]);

    $out = sys_get_temp_dir().'/braintrust-dataset-'.getmypid().'.ndjson';

    $this->artisan('stub:eval', ['target' => 'stub-classifier', '--dataset' => 'braintrust:maintenance-hazard-photos', '--provider' => 'openai', '--out' => $out])
        ->assertSuccessful();

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->attachments[0] instanceof Base64Image
        && $prompt->attachments[0]->content() === 'bytes-of-att-1');

    expect((float) json_decode(File::get($out), true)['scores']['gas'])->toBe(1.0);

    File::delete($out);
});

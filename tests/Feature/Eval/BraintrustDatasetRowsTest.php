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
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Classification;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
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
    $events = array_map(fn (int $i): array => ['id' => "row-{$i}", 'input' => ['report' => "row {$i}"], 'expected' => ['set' => 'must_catch'], 'tags' => null], range(1, 100));

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [['id' => 'ds-1']]]),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::sequence()
            ->push(['events' => $events, 'cursor' => 'page-2'])
            ->push(['events' => [
                ['id' => 'row-101', 'input' => 'a plain prompt', 'expected' => false, 'tags' => ['keep']],
                ['id' => 'row-102', 'input' => ['report' => 'own', 'expected' => 'mine'], 'expected' => 'theirs'],
            ], 'cursor' => 'page-3'])
            ->push(['events' => []]),
    ]);

    $rows = new BraintrustApi()->datasetRows('photo-reports');

    expect($rows)->toHaveCount(102)
        ->and($rows[0])->toBe(['report' => 'row 1', 'id' => 'row-1', 'expected' => ['set' => 'must_catch']])
        ->and($rows[100])->toBe(['input' => 'a plain prompt', 'id' => 'row-101', 'expected' => false, 'tags' => ['keep']])
        ->and($rows[101])->toBe(['report' => 'own', 'expected' => 'mine', 'id' => 'row-102']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'dataset_name=photo-reports')
        && str_contains($request->url(), 'project_id=proj-1'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/fetch') && ($request->data()['cursor'] ?? null) === 'page-3');
});

it('keeps going while pages come back short, and drops older versions of a row', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [['id' => 'ds-1']]]),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::sequence()
            ->push(['events' => [['id' => 'a', 'input' => ['report' => 'edited']]], 'cursor' => 'page-2'])
            ->push(['events' => [['id' => 'a', 'input' => ['report' => 'original']], ['id' => 'b', 'input' => ['report' => 'second']]], 'cursor' => 'page-3'])
            ->push(['events' => [], 'cursor' => null]),
    ]);

    expect(new BraintrustApi()->datasetRows('photo-reports'))->toBe([['report' => 'edited', 'id' => 'a'], ['report' => 'second', 'id' => 'b']]);
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

it('replaces nested attachment references with local files, downloading each one once', function (): void {
    fakeBraintrustAttachmentApi();
    $directory = sys_get_temp_dir().'/braintrust-attachments-'.getmypid();

    $attachments = new BraintrustAttachments(new BraintrustApi, $directory);

    $first = $attachments->resolve(['report' => 'gas', 'photos' => [braintrustPhotoReference()], 'meta' => ['n' => 1]]);
    $second = $attachments->resolve(['photos' => [braintrustPhotoReference(), braintrustPhotoReference('att-2', 'lease.pdf', 'application/pdf')]]);

    expect($first['report'])->toBe('gas')
        ->and($first['meta'])->toBe(['n' => 1])
        ->and($first['photos'][0])->toBeInstanceOf(LocalImage::class)
        ->and($first['photos'][0]->path)->toStartWith($directory.'/')
        ->and($first['photos'][0]->content())->toBe('bytes-of-att-1')
        ->and($first['photos'][0]->mimeType())->toBe('image/jpeg')
        ->and($first['photos'][0]->name())->toBe('meter.jpg')
        ->and($second['photos'][1])->toBeInstanceOf(LocalDocument::class)
        ->and($second['photos'][1]->content())->toBe('bytes-of-att-2');

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://bucket.test/att-1'))->toHaveCount(1);

    File::deleteDirectory($directory);
});

it('keeps a photo-sized attachment out of the row a forked process is handed', function (): void {
    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1', 'org_id' => 'org-1']),
        'api.braintrust.dev/attachment?*' => Http::response(['downloadUrl' => 'https://bucket.test/att-1', 'status' => ['upload_status' => 'done']]),
        'bucket.test/*' => Http::response(str_repeat('x', 2_000_000)),
    ]);
    $directory = sys_get_temp_dir().'/braintrust-attachments-fork-'.getmypid();

    $row = new BraintrustAttachments(new BraintrustApi, $directory)->resolve(['photo' => braintrustPhotoReference()]);

    // The process driver passes each task through an environment variable, capped at 128 KB on Linux.
    expect(strlen(serialize($row)))->toBeLessThan(1_000)
        ->and(strlen(unserialize(serialize($row))['photo']->content()))->toBe(2_000_000);

    File::deleteDirectory($directory);
});

it('runs a classifier eval over a Braintrust dataset with its photos attached', function (): void {
    config()->set('ai-companion.eval.harness', StubHarness::class);
    config()->set('ai-companion.eval.targets', [ClassifierStubTarget::class]);
    $this->app[Kernel::class]->registerCommand(new StubEvalCommand);
    $this->app->bind(ConcurrencyRunner::class, RecordingConcurrencyRunner::class);

    fakeBraintrustAttachmentApi([
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [['id' => 'ds-1']]]),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::response(['events' => [
            ['id' => 'row-1', 'input' => ['decision' => 'hazard', 'state' => 'Smell of gas', 'attachments' => [braintrustPhotoReference()]], 'expected' => ['gas' => true]],
        ]]),
        'api.braintrust.dev/v1/experiment' => Http::response(['id' => 'exp-1']),
        'api.braintrust.dev/v1/experiment/exp-1/insert' => Http::response(['row_ids' => ['1']]),
    ]);
    Process::fake();
    Classification::fake([['gas' => new BooleanAnswer(0.9)]]);

    $this->artisan('stub:eval', ['target' => 'stub-classifier', '--dataset' => 'braintrust:photo-reports', '--provider' => 'openai', '--model' => 'gpt-test'])
        ->assertSuccessful();

    $photo = null;
    Classification::assertClassified(function (ClassificationPrompt $prompt) use (&$photo): bool {
        $photo = $prompt->attachments[0];

        return $photo instanceof LocalImage && $photo->name() === 'meter.jpg';
    });

    expect(File::exists(dirname($photo->path)))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/experiment')
        && $request->data()['name'] === 'stub-classifier/photo-reports/openai/gpt-test');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/insert')
        && (float) $request->data()['events'][0]['scores']['gas'] === 1.0
        && $request->data()['events'][0]['metadata']['row_id'] === 'row-1');
});

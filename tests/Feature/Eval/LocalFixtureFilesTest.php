<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Eval\LocalFixtureFiles;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\DocumentStubTarget;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\RecordingConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubEvalCommand;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubHarness;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\TextStubAgent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;

beforeEach(function (): void {
    Http::preventStrayRequests();
    File::ensureDirectoryExists(base_path('fixtures'));
    File::put(base_path('fixtures/epc.pdf'), '%PDF-1.4 epc');
    File::put(base_path('fixtures/meter.png'), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAX+XDSwAAAABJRU5ErkJggg=='));
});

afterEach(function (): void {
    File::deleteDirectory(base_path('fixtures'));
    File::delete(dirname(base_path()).'/outside-fixture.txt');
    File::delete(base_path('eval-dataset.json'));
    File::delete(sys_get_temp_dir().'/stub-document.ndjson');
});

function localFileReference(string $path, array $extra = []): array
{
    return ['type' => 'local_file', 'path' => $path, ...$extra];
}

it('replaces nested local file references with SDK files pointing at the fixture in place', function (): void {
    $row = new LocalFixtureFiles()->resolve([
        'brief' => 'read it',
        'document' => localFileReference('fixtures/epc.pdf'),
        'photos' => [localFileReference('fixtures/meter.png')],
        'meta' => ['n' => 1],
    ]);

    expect($row['brief'])->toBe('read it')
        ->and($row['meta'])->toBe(['n' => 1])
        ->and($row['document'])->toBeInstanceOf(LocalDocument::class)
        ->and($row['document']->path)->toBe(realpath(base_path('fixtures/epc.pdf')))
        ->and($row['document']->content())->toBe('%PDF-1.4 epc')
        ->and($row['document']->name())->toBe('epc.pdf')
        ->and($row['document']->mimeType())->toBe('application/pdf')
        ->and($row['photos'][0])->toBeInstanceOf(LocalImage::class)
        ->and($row['photos'][0]->mimeType())->toBe('image/png');
});

it('lets a reference override the file name and content type', function (): void {
    $row = new LocalFixtureFiles()->resolve([
        'document' => localFileReference('fixtures/epc.pdf', ['filename' => 'certificate.pdf', 'content_type' => 'application/x-custom']),
    ]);

    expect($row['document']->name())->toBe('certificate.pdf')
        ->and($row['document']->mimeType())->toBe('application/x-custom');
});

it('rejects a fixture file that does not exist', function (): void {
    new LocalFixtureFiles()->resolve(['document' => localFileReference('fixtures/missing.pdf')]);
})->throws(RuntimeException::class, 'Eval fixture file [fixtures/missing.pdf] does not exist.');

it('rejects a fixture path that leaves the base path', function (): void {
    File::put(dirname(base_path()).'/outside-fixture.txt', 'secret');

    new LocalFixtureFiles()->resolve(['document' => localFileReference('../outside-fixture.txt')]);
})->throws(RuntimeException::class, 'Eval fixture file [../outside-fixture.txt] is outside the application base path.');

it('sends a local dataset row\'s fixture to the agent as a prompt attachment', function (): void {
    config()->set('ai-companion.eval.harness', StubHarness::class);
    config()->set('ai-companion.eval.targets', [DocumentStubTarget::class]);
    $this->app[Kernel::class]->registerCommand(new StubEvalCommand);
    $this->app->bind(ConcurrencyRunner::class, RecordingConcurrencyRunner::class);

    TextStubAgent::fake(['rated C']);
    File::put(base_path('eval-dataset.json'), json_encode([
        ['brief' => 'read the certificate', 'document' => localFileReference('fixtures/epc.pdf')],
    ]));

    $this->artisan('stub:eval', ['target' => 'stub-document', '--out' => sys_get_temp_dir().'/stub-document.ndjson'])
        ->assertSuccessful();

    TextStubAgent::assertPrompted(function ($prompt): bool {
        $document = $prompt->attachments->first();

        return $prompt->attachments->count() === 1
            && $document instanceof LocalDocument
            && $document->name() === 'epc.pdf'
            && $document->content() === '%PDF-1.4 epc';
    });
});

it('sends a Braintrust dataset row\'s fixture to the agent as a prompt attachment', function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');
    config()->set('ai-companion.eval.harness', StubHarness::class);
    config()->set('ai-companion.eval.targets', [DocumentStubTarget::class]);
    $this->app[Kernel::class]->registerCommand(new StubEvalCommand);
    $this->app->bind(ConcurrencyRunner::class, RecordingConcurrencyRunner::class);

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1', 'org_id' => 'org-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [['id' => 'ds-1']]]),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::response(['events' => [
            ['id' => 'row-1', 'input' => ['brief' => 'read it', 'document' => localFileReference('fixtures/epc.pdf')]],
        ]]),
        'api.braintrust.dev/v1/experiment' => Http::response(['id' => 'exp-1']),
        'api.braintrust.dev/v1/experiment/exp-1/insert' => Http::response(['row_ids' => ['1']]),
    ]);
    TextStubAgent::fake(['rated C']);

    $this->artisan('stub:eval', ['target' => 'stub-document', '--dataset' => 'braintrust:epc'])->assertSuccessful();

    TextStubAgent::assertPrompted(fn ($prompt): bool => $prompt->attachments->first() instanceof LocalDocument);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/attachment'));
});

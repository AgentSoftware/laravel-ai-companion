<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalFixture;
use AgentSoftware\LaravelAiCompanion\Eval\LocalFixtureFiles;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;

beforeEach(function (): void {
    config()->set('ai-companion.eval.fixtures_path', 'eval-fixture-tests/library');
    File::ensureDirectoryExists(base_path('eval-fixture-tests/library/epc'));
    File::put(base_path('eval-fixture-tests/library/epc.pdf'), '%PDF-1.4 epc');
    File::put(base_path('eval-fixture-tests/library/epc/sample-1.pdf'), '%PDF-1.4 sample');
    File::put(base_path('eval-fixture-tests/library/meter.png'), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAX+XDSwAAAABJRU5ErkJggg=='));
    File::put(base_path('eval-fixture-tests/secret.txt'), 'secret');
});

afterEach(function (): void {
    File::deleteDirectory(base_path('eval-fixture-tests'));
    File::deleteDirectory(base_path('custom'));
});

it('keeps fixtures in resources/ai/fixtures by default', function (): void {
    expect((require __DIR__.'/../../../config/ai-companion.php')['eval']['fixtures_path'])->toBe('resources/ai/fixtures');
});

it('resolves a fixture row to an SDK file in the fixtures folder', function (): void {
    $row = new LocalFixtureFiles()->resolve([
        'document' => ['type' => 'fixture', 'name' => 'epc.pdf'],
        'photos' => [['type' => 'fixture', 'name' => 'meter.png']],
    ]);

    expect($row['document'])->toBeInstanceOf(LocalDocument::class)
        ->and($row['document']->path)->toBe(realpath(base_path('eval-fixture-tests/library/epc.pdf')))
        ->and($row['document']->name())->toBe('epc.pdf')
        ->and($row['document']->mimeType())->toBe('application/pdf')
        ->and($row['photos'][0])->toBeInstanceOf(LocalImage::class);
});

it('resolves a fixture in a subfolder and honours filename and content type overrides', function (): void {
    $row = new LocalFixtureFiles()->resolve([
        'document' => ['type' => 'fixture', 'name' => 'epc/sample-1.pdf', 'filename' => 'certificate.pdf', 'content_type' => 'application/x-custom'],
    ]);

    expect($row['document']->content())->toBe('%PDF-1.4 sample')
        ->and($row['document']->name())->toBe('certificate.pdf')
        ->and($row['document']->mimeType())->toBe('application/x-custom');
});

it('resolves a fixture row to the same file as the equivalent local_file row', function (): void {
    $row = new LocalFixtureFiles()->resolve([
        'fixture' => ['type' => 'fixture', 'name' => 'epc.pdf'],
        'local' => ['type' => 'local_file', 'path' => 'eval-fixture-tests/library/epc.pdf'],
    ]);

    expect($row['fixture'])->toEqual($row['local']);
});

it('reads fixtures from the configured fixtures folder', function (): void {
    File::ensureDirectoryExists(base_path('custom/samples'));
    File::put(base_path('custom/samples/epc.pdf'), '%PDF-1.4 custom');
    config()->set('ai-companion.eval.fixtures_path', 'custom/samples/');

    $row = new LocalFixtureFiles()->resolve(['document' => ['type' => 'fixture', 'name' => 'epc.pdf']]);

    expect($row['document']->content())->toBe('%PDF-1.4 custom');
});

it('rejects a fixture that does not exist', function (): void {
    new LocalFixtureFiles()->resolve(['document' => ['type' => 'fixture', 'name' => 'missing.pdf']]);
})->throws(RuntimeException::class, 'Eval fixture [missing.pdf] does not exist in [eval-fixture-tests/library].');

it('rejects a fixture name that leaves the fixtures folder', function (): void {
    new LocalFixtureFiles()->resolve(['document' => ['type' => 'fixture', 'name' => '../secret.txt']]);
})->throws(RuntimeException::class, 'Eval fixture [../secret.txt] is outside the fixtures folder [eval-fixture-tests/library].');

it('rejects a fixture row without a name', function (): void {
    new LocalFixtureFiles()->resolve(['document' => ['type' => 'fixture']]);
})->throws(RuntimeException::class, 'Eval fixture [] does not exist');

it('gives the absolute path of a fixture', function (): void {
    expect(EvalFixture::path('epc/sample-1.pdf'))->toBe(realpath(base_path('eval-fixture-tests/library/epc/sample-1.pdf')));
});

it('refuses a helper path that leaves the fixtures folder', function (): void {
    EvalFixture::path('../secret.txt');
})->throws(RuntimeException::class, 'outside the fixtures folder');

it('gives an SDK file for a fixture', function (): void {
    expect(EvalFixture::file('epc.pdf'))->toBeInstanceOf(LocalDocument::class)
        ->and(EvalFixture::file('epc.pdf')->name())->toBe('epc.pdf')
        ->and(EvalFixture::file('meter.png'))->toBeInstanceOf(LocalImage::class);
});

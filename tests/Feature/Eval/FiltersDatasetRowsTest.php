<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\FilteringStubTarget;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\RecordingConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubEvalCommand;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubHarness;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\TextStubAgent;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\TextStubTarget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    config()->set('ai-companion.eval.harness', StubHarness::class);
    config()->set('ai-companion.eval.targets', [FilteringStubTarget::class, TextStubTarget::class]);

    $this->app[Kernel::class]->registerCommand(new StubEvalCommand);

    RecordingConcurrencyRunner::reset();
    $this->app->bind(ConcurrencyRunner::class, RecordingConcurrencyRunner::class);

    $this->out = sys_get_temp_dir().'/filters-dataset-rows-'.getmypid().'.ndjson';
    File::put(base_path('filters-dataset-rows.json'), json_encode([
        ['brief' => 'draft one', 'expected' => ''],
        ['brief' => 'kept one', 'expected' => 'answer one'],
        ['brief' => 'draft two'],
        ['brief' => 'kept two', 'expected' => 'answer two'],
        ['brief' => 'kept three', 'expected' => 'answer three'],
    ]));
});

afterEach(function (): void {
    File::delete(base_path('filters-dataset-rows.json'));
    File::delete($this->out);
});

it('does not evaluate rows rejected by the target', function (): void {
    TextStubAgent::fake(['a', 'b', 'c']);

    $this->artisan('stub:eval', ['target' => 'stub-filter', '--out' => $this->out, '--dataset' => 'filters-dataset-rows.json'])->assertSuccessful();

    TextStubAgent::assertPrompted(fn ($prompt): bool => $prompt->prompt === 'kept one');
    TextStubAgent::assertNotPrompted(fn ($prompt): bool => str_starts_with($prompt->prompt, 'draft'));
    expect(File::lines($this->out)->filter()->count())->toBe(3);
});

it('prints how many rows the target skipped', function (): void {
    TextStubAgent::fake(['a', 'b', 'c']);

    $this->artisan('stub:eval', ['target' => 'stub-filter', '--out' => $this->out, '--dataset' => 'filters-dataset-rows.json'])
        ->expectsOutputToContain('Skipped 2 rows (excluded by target).')
        ->assertSuccessful();
});

it('is unaffected for targets without the contract', function (): void {
    TextStubAgent::fake(['a', 'b', 'c', 'd', 'e']);

    $this->artisan('stub:eval', ['target' => 'stub-text', '--out' => $this->out, '--dataset' => 'filters-dataset-rows.json'])
        ->doesntExpectOutputToContain('Skipped')
        ->assertSuccessful();

    expect(File::lines($this->out)->filter()->count())->toBe(5);
});

it('does not print a skipped line when the target excludes nothing', function (): void {
    File::put(base_path('filters-dataset-rows.json'), json_encode([['brief' => 'kept', 'expected' => 'x']]));
    TextStubAgent::fake(['a']);

    $this->artisan('stub:eval', ['target' => 'stub-filter', '--out' => $this->out, '--dataset' => 'filters-dataset-rows.json'])
        ->doesntExpectOutputToContain('Skipped')
        ->assertSuccessful();
});

it('applies --limit after exclusion', function (): void {
    TextStubAgent::fake(['a', 'b', 'c']);

    $this->artisan('stub:eval', ['target' => 'stub-filter', '--limit' => 2, '--out' => $this->out, '--dataset' => 'filters-dataset-rows.json'])
        ->expectsOutputToContain('Skipped 2 rows (excluded by target).')
        ->assertSuccessful();

    TextStubAgent::assertPrompted(fn ($prompt): bool => $prompt->prompt === 'kept two');
    TextStubAgent::assertNotPrompted(fn ($prompt): bool => $prompt->prompt === 'kept three');
    expect(File::lines($this->out)->filter()->count())->toBe(2);
});

it('exports string expected values', function (): void {
    TextStubAgent::fake(['a', 'b', 'c']);

    $this->artisan('stub:eval', ['target' => 'stub-filter', '--out' => $this->out, '--dataset' => 'filters-dataset-rows.json'])->assertSuccessful();

    $events = File::lines($this->out)->filter()->map(fn (string $line): array => json_decode($line, true))->values();

    expect($events->pluck('expected')->all())->toBe(['answer one', 'answer two', 'answer three']);
});

it('exports array expected values unchanged', function (): void {
    File::put(base_path('filters-dataset-rows.json'), json_encode([['brief' => 'structured', 'expected' => ['tool' => 'search']]]));
    TextStubAgent::fake(['a']);

    $this->artisan('stub:eval', ['target' => 'stub-text', '--out' => $this->out, '--dataset' => 'filters-dataset-rows.json'])->assertSuccessful();

    $event = json_decode(File::lines($this->out)->filter()->first(), true);

    expect($event['expected'])->toBe(['tool' => 'search']);
});

it('omits null or empty expected values', function (): void {
    File::put(base_path('filters-dataset-rows.json'), json_encode([
        ['brief' => 'empty', 'expected' => ''],
        ['brief' => 'missing'],
        ['brief' => 'null', 'expected' => null],
    ]));
    TextStubAgent::fake(['a', 'b', 'c']);

    $this->artisan('stub:eval', ['target' => 'stub-text', '--out' => $this->out, '--dataset' => 'filters-dataset-rows.json'])->assertSuccessful();

    $events = File::lines($this->out)->filter()->map(fn (string $line): array => json_decode($line, true));

    expect($events)->toHaveCount(3)
        ->and($events->every(fn (array $event): bool => ! array_key_exists('expected', $event)))->toBeTrue();
});

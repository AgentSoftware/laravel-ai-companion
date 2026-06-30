<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\AttachmentStubTarget;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StructuredStubAgent;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StructuredStubTarget;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubEvalCommand;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubHarness;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\TextStubAgent;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\TextStubTarget;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\ThrowStubTarget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Responses\Data\ToolCall;

beforeEach(function (): void {
    config()->set('ai-companion.eval.harness', StubHarness::class);
    config()->set('ai-companion.eval.targets', [
        StructuredStubTarget::class,
        TextStubTarget::class,
        ThrowStubTarget::class,
    ]);

    $this->app[Kernel::class]->registerCommand(new StubEvalCommand);
});

afterEach(function (): void {
    File::delete(base_path('eval-dataset.json'));
    File::delete(storage_path('app/braintrust/stub.ndjson'));
});

function writeEvalDataset(array $rows): void
{
    File::put(base_path('eval-dataset.json'), json_encode($rows));
}

/**
 * @return array<int, array<string, mixed>>
 */
function readNdjson(string $path): array
{
    return collect(explode("\n", trim(File::get($path))))
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
        ->all();
}

it('scores a structured target and writes scored NDJSON', function (): void {
    StructuredStubAgent::fake([['name' => 'Spring Sale Event']]);
    writeEvalDataset([['brief' => 'a spring sale', 'tags' => ['sale']]]);

    $this->artisan('stub:eval', ['target' => 'stub'])->assertSuccessful();

    $rows = readNdjson(storage_path('app/braintrust/stub.ndjson'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['input']['input'])->toBe('a spring sale')
        ->and((float) $rows[0]['scores']['alpha'])->toBe(0.9)
        ->and((float) $rows[0]['scores']['beta'])->toBe(0.6)
        ->and((float) $rows[0]['scores']['gamma'])->toBe(0.3)
        ->and($rows[0]['metadata']['prompt_name'])->toBe('stub')
        ->and($rows[0]['metadata']['prompt_version'])->toBe(2)
        ->and($rows[0]['metadata']['tags'])->toBe(['sale']);
});

it('pushes a Braintrust experiment named after the target', function (): void {
    config()->set('ai-companion.braintrust.api_key', 'k');
    config()->set('ai-companion.braintrust.project', 'Evals');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/experiment' => Http::response(['id' => 'exp-1']),
        'api.braintrust.dev/v1/experiment/exp-1/insert' => Http::response(['row_ids' => ['1']]),
    ]);

    Process::fake([
        'git rev-parse --abbrev-ref HEAD' => Process::result('feature/x'),
        'git status --porcelain' => Process::result(''),
        '*' => Process::result('abc123'),
    ]);

    StructuredStubAgent::fake([['name' => 'Tagged One']]);
    writeEvalDataset([
        ['brief' => 'tagged', 'tags' => ['keep']],
        ['brief' => 'other', 'tags' => ['skip']],
    ]);

    $this->artisan('stub:eval', ['target' => 'stub', '--tag' => 'keep', '--limit' => 1])
        ->assertSuccessful();

    expect(File::exists(storage_path('app/braintrust/stub.ndjson')))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/experiment')
        && str_starts_with($request->data()['name'], 'stub/')
        && str_contains($request->data()['name'], 'tag-keep')
        && str_contains($request->data()['name'], 'first-1')
        && $request->data()['repo_info']['branch'] === 'feature/x'
        && $request->data()['metadata']['catalogue'] === ['x']);
});

it('captures tool calls and reply text for a text target', function (): void {
    TextStubAgent::fake([new ToolCall('c-1', 'FooTool', []), 'all done']);
    writeEvalDataset([['brief' => 'do a foo']]);

    $out = sys_get_temp_dir().'/stub-text.ndjson';

    $this->artisan('stub:eval', ['target' => 'stub-text', '--out' => $out])->assertSuccessful();

    $row = readNdjson($out)[0];

    expect($row['output']['tool_calls'])->toContain('FooTool')
        ->and((float) $row['scores']['routing'])->toBe(1.0)
        ->and($row['metadata']['prompt_version'])->toBeNull();

    File::delete($out);
});

it('passes a target\'s attachments through to the agent', function (): void {
    config()->set('ai-companion.eval.targets', [AttachmentStubTarget::class]);

    TextStubAgent::fake(['captioned']);
    writeEvalDataset([['brief' => 'caption this image']]);

    $out = sys_get_temp_dir().'/stub-attach.ndjson';

    $this->artisan('stub:eval', ['target' => 'stub-attach', '--out' => $out])->assertSuccessful();

    TextStubAgent::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() === 1);

    File::delete($out);
});

it('runs each row trials times', function (): void {
    StructuredStubAgent::fake([['name' => 'One'], ['name' => 'Two']]);
    writeEvalDataset([['brief' => 'once']]);

    $this->artisan('stub:eval', ['target' => 'stub', '--trials' => 2])->assertSuccessful();

    expect(readNdjson(storage_path('app/braintrust/stub.ndjson')))->toHaveCount(2);
});

it('prompts for the target when none is given', function (): void {
    StructuredStubAgent::fake([['name' => 'Picked']]);
    writeEvalDataset([['brief' => 'pick me']]);

    $this->artisan('stub:eval')
        ->expectsChoice('Which agent do you want to eval?', 'stub', [
            'stub' => 'Structured stub',
            'stub-text' => 'Text stub',
            'stub-throw' => 'Throwing stub',
        ])
        ->assertSuccessful();
});

it('fails when every row errors', function (): void {
    writeEvalDataset([['brief' => 'x']]);

    $this->artisan('stub:eval', ['target' => 'stub-throw'])->assertFailed();
});

it('fails when the dataset file is missing', function (): void {
    $this->artisan('stub:eval', ['target' => 'stub'])->assertFailed();
});

it('fails on an empty dataset', function (): void {
    writeEvalDataset([]);

    $this->artisan('stub:eval', ['target' => 'stub'])->assertFailed();
});

it('fails when no harness is configured', function (): void {
    config()->set('ai-companion.eval.harness', null);
    writeEvalDataset([['brief' => 'x']]);

    $this->artisan('stub:eval', ['target' => 'stub'])->assertFailed();
});

it('fails when no targets are configured', function (): void {
    config()->set('ai-companion.eval.targets', []);

    $this->artisan('stub:eval', ['target' => 'stub'])->assertFailed();
});

it('fails on an unknown target', function (): void {
    writeEvalDataset([['brief' => 'x']]);

    $this->artisan('stub:eval', ['target' => 'nope'])->assertFailed();
});

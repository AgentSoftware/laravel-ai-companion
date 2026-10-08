<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\ClassifierStubTarget;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\RecordingConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubEvalCommand;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StubHarness;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

beforeEach(function (): void {
    config()->set('ai-companion.eval.harness', StubHarness::class);
    config()->set('ai-companion.eval.targets', [ClassifierStubTarget::class]);

    $this->app[Kernel::class]->registerCommand(new StubEvalCommand);

    RecordingConcurrencyRunner::reset();
    $this->app->bind(ConcurrencyRunner::class, RecordingConcurrencyRunner::class);

    $this->out = sys_get_temp_dir().'/stub-classifier-'.getmypid().'.ndjson';
});

afterEach(function (): void {
    File::delete(base_path(classifierDatasetPath()));
    File::delete($this->out);
});

function classifierDatasetPath(): string
{
    return 'classifier-dataset-'.getmypid().'.json';
}

function writeClassifierDataset(array $rows): void
{
    File::put(base_path(classifierDatasetPath()), json_encode($rows));
}

/**
 * @return array<int, array<string, mixed>>
 */
function readClassifierNdjson(string $path): array
{
    return collect(explode("\n", trim(File::get($path))))
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
        ->all();
}

it('classifies each row, scores the answers, and writes them with the expected answers', function (): void {
    Classification::fake([
        ['priority' => new ChoiceAnswer('urgent', ['routine' => 0.2, 'urgent' => 0.8], 0.8)],
        ['gas' => new BooleanAnswer(0.9)],
    ]);
    writeClassifierDataset([
        ['decision' => 'priority', 'state' => 'The boiler is leaking', 'expected' => ['priority' => 'urgent'], 'tags' => ['priority']],
        ['decision' => 'hazard', 'state' => ['report' => 'I can smell gas'], 'expected' => ['gas' => ['answer' => true, 'tag' => 'must_catch']]],
    ]);

    $this->artisan('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--out' => $this->out])->assertSuccessful();

    [$priority, $hazard] = readClassifierNdjson($this->out);

    expect($priority['input']['state'])->toBe('The boiler is leaking')
        ->and($priority['input']['questions']['priority']['type'])->toBe('choice')
        ->and($priority['output']['priority']['choice'])->toBe('urgent')
        ->and($priority['expected'])->toBe(['priority' => ['answer' => 'urgent']])
        ->and((float) $priority['scores']['priority'])->toBe(1.0)
        ->and($priority['scores'])->not->toHaveKey('gas')
        ->and($priority['metadata']['provider'])->toBe('typesafe')
        ->and($priority['metadata']['tags'])->toBe(['priority'])
        ->and($hazard['input']['state'])->toBe(['report' => 'I can smell gas'])
        ->and((float) $hazard['output']['gas']['probability'])->toBe(0.9)
        ->and($hazard['expected'])->toBe(['gas' => ['answer' => true, 'tag' => 'must_catch']])
        ->and((float) $hazard['scores']['gas'])->toBe(1.0)
        ->and((float) $hazard['scores']['gas_must_catch'])->toBe(1.0)
        ->and($hazard['scores'])->not->toHaveKey('gas_must_pass');
});

it('runs the classification against the provider and model given on the command line', function (): void {
    Classification::fake([['gas' => new BooleanAnswer(0.1)]]);
    writeClassifierDataset([['decision' => 'hazard', 'state' => 'A dripping tap', 'expected' => ['gas' => false]]]);

    $this->artisan('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--out' => $this->out, '--provider' => 'openai', '--model' => 'gpt-test'])
        ->assertSuccessful();

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->provider->name() === 'openai'
        && $prompt->model === 'gpt-test'
        && $prompt->asks('gas'));

    expect(readClassifierNdjson($this->out)[0]['metadata'])
        ->toMatchArray(['provider' => 'openai', 'model' => 'gpt-test']);
});

it('names the Braintrust experiment after the provider and model', function (): void {
    config()->set('ai-companion.braintrust.api_key', 'k');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/experiment' => Http::response(['id' => 'exp-1']),
        'api.braintrust.dev/v1/experiment/exp-1/insert' => Http::response(['row_ids' => ['1']]),
    ]);
    Process::fake();

    Classification::fake([['gas' => new BooleanAnswer(0.9)]]);
    writeClassifierDataset([['decision' => 'hazard', 'state' => 'I can smell gas', 'expected' => ['gas' => true]]]);

    $this->artisan('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--provider' => 'openai', '--model' => 'gpt-test'])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/experiment')
        && $request->data()['name'] === 'stub-classifier/openai/gpt-test');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/insert')
        && $request->data()['events'][0]['input']['state'] === 'I can smell gas'
        && $request->data()['events'][0]['expected'] === ['gas' => ['answer' => true]]
        && $request->data()['events'][0]['output']['gas'] === ['probability' => 0.9]
        && $request->data()['events'][0]['metadata']['scores']['gas']['probability'] === 0.9);
});

it('fails the run when a must-catch row is missed, after still writing the results', function (): void {
    Classification::fake([['gas' => new BooleanAnswer(0.2)], ['gas' => new BooleanAnswer(0.7)]]);
    writeClassifierDataset([
        ['decision' => 'hazard', 'state' => 'The CO alarm keeps beeping', 'expected' => ['gas' => ['answer' => true, 'tag' => 'must_catch']]],
        ['decision' => 'hazard', 'state' => 'A dripping tap', 'expected' => ['gas' => ['answer' => false, 'tag' => 'must_pass']]],
    ]);

    $status = Artisan::call('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--out' => $this->out]);
    $output = Artisan::output();

    [$missed, $held] = readClassifierNdjson($this->out);

    expect($status)->toBe(Command::FAILURE)
        ->and($output)->toContain('1 blocking score(s) missed')
        ->and($output)->toContain('gas_must_catch — The CO alarm keeps beeping')
        ->and((float) $missed['scores']['gas_must_catch'])->toBe(0.0)
        ->and((float) $held['scores']['gas_must_pass'])->toBe(0.0);
});

it('prints a confusion matrix for each classification question', function (): void {
    Classification::fake([['gas' => new BooleanAnswer(0.9)], ['gas' => new BooleanAnswer(0.8)], ['gas' => new BooleanAnswer(0.1)]]);
    writeClassifierDataset([
        ['decision' => 'hazard', 'state' => 'Gas smell', 'expected' => ['gas' => true]],
        ['decision' => 'hazard', 'state' => 'Dripping tap', 'expected' => ['gas' => false]],
        ['decision' => 'hazard', 'state' => 'Broken fence', 'expected' => ['gas' => false]],
    ]);

    Artisan::call('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--out' => $this->out]);
    $output = Artisan::output();

    expect($output)->toContain('Gas — confusion (rows expected, columns actual)')
        ->and($output)->toMatch('/false\s+│\s+1\s+│\s+1\s+│/u')
        ->and($output)->toMatch('/true\s+│\s+0\s+│\s+1\s+│/u')
        ->and($output)->not->toContain('Gas Must Catch — confusion');
});

it('passes a row\'s attachments to the classification', function (): void {
    Classification::fake([['gas' => new BooleanAnswer(0.9)]]);
    writeClassifierDataset([[
        'decision' => 'hazard',
        'state' => 'See the photo',
        'attachments' => [['type' => 'remote-image', 'url' => 'https://example.test/meter.jpg']],
    ]]);

    $this->artisan('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--out' => $this->out, '--provider' => 'openai'])
        ->assertSuccessful();

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => count($prompt->attachments) === 1);
});

it('fails the run when a classification fails, without stopping the other rows', function (): void {
    Classification::fake([['gas' => new BooleanAnswer(0.9)]]);
    writeClassifierDataset([
        ['decision' => 'hazard', 'state' => 'Gas smell', 'expected' => ['gas' => true]],
        ['decision' => 'hazard', 'state' => ['report' => 'Photo of the meter'], 'attachments' => [['type' => 'remote-image', 'url' => 'https://example.test/meter.jpg']]],
    ]);

    $status = Artisan::call('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--out' => $this->out]);
    $output = Artisan::output();

    expect($status)->toBe(Command::FAILURE)
        ->and($output)->toContain('1 run(s) failed')
        ->and($output)->toContain('{"report":"Photo of the meter"} — Provider [typesafe] does not support classification attachments.')
        ->and($output)->toContain('1 classification(s) failed to run, so their gates were not measured.')
        ->and(readClassifierNdjson($this->out))->toHaveCount(1);
});

it('reports a row the target cannot turn into a classification as a failed run', function (): void {
    Classification::fake([['gas' => new BooleanAnswer(0.9)]]);
    writeClassifierDataset([
        ['decision' => 'hazard', 'state' => 'Gas smell', 'expected' => ['gas' => true]],
        ['decision' => 'hazard', 'state' => 'Gas smell again', 'expected' => ['gas' => ['tag' => 'must_catch']]],
    ]);

    $status = Artisan::call('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--out' => $this->out]);

    expect($status)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('An expected answer must be true, false or a choice')
        ->and(readClassifierNdjson($this->out))->toHaveCount(1);
});

it('runs a classifier target without a harness', function (): void {
    config()->set('ai-companion.eval.harness', null);
    Classification::fake([['gas' => new BooleanAnswer(0.9)]]);
    writeClassifierDataset([['decision' => 'hazard', 'state' => 'Gas smell', 'expected' => ['gas' => true]]]);

    $this->artisan('stub:eval', ['target' => 'stub-classifier', '--dataset' => classifierDatasetPath(), '--out' => $this->out])
        ->assertSuccessful();

    expect((float) readClassifierNdjson($this->out)[0]['scores']['gas'])->toBe(1.0);
});

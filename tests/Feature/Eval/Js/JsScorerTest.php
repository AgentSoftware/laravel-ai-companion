<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Js\JsScorer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function jsScorerFixture(): string
{
    return dirname(__DIR__, 3).'/Fixtures/Js/passing-scorer.js';
}

it('derives its score name from the file slug', function (): void {
    expect((new JsScorer('/tmp/no-hallucinated-unsplash-urls.js'))->name())->toBe('no_hallucinated_unsplash_urls');
});

it('exposes the file contents as code', function (): void {
    expect((new JsScorer(jsScorerFixture()))->code())->toContain('async function handler');
});

it('scores via the node runner and parses the result', function (): void {
    Process::fake(['*' => Process::result(output: '{"score":0.8,"metadata":{"why":"ok"}}')]);

    $score = (new JsScorer(jsScorerFixture()))->score(new EvalSubject(output: ['text' => 'good']));

    expect($score->score)->toBe(0.8)
        ->and($score->name)->toBe('passing_scorer')
        ->and($score->metadata['why'])->toBe('ok');

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'scorer-runner.mjs')
        && str_contains((string) $process->input, '"output"'));
});

it('accepts a bare number result and clamps out-of-range scores', function (): void {
    Process::fake(['*' => Process::result(output: '7')]);

    expect((new JsScorer(jsScorerFixture()))->score(new EvalSubject(output: []))->score)->toBe(1.0);
});

it('returns 0.0 with the error in metadata when the runner fails', function (): void {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'ReferenceError: boom', exitCode: 1)]);

    $score = (new JsScorer(jsScorerFixture()))->score(new EvalSubject(output: []));

    expect($score->score)->toBe(0.0)
        ->and($score->metadata['error'])->toContain('ReferenceError: boom');
});

it('returns 0.0 with an error when the runner prints invalid json', function (): void {
    Process::fake(['*' => Process::result(output: 'not json')]);

    $score = (new JsScorer(jsScorerFixture()))->score(new EvalSubject(output: []));

    expect($score->score)->toBe(0.0)
        ->and($score->metadata['error'])->toContain('JSON');
});

it('really runs the scorer through node when available', function (): void {
    if (Process::run(['which', 'node'])->failed()) {
        $this->markTestSkipped('node not installed');
    }

    $score = (new JsScorer(jsScorerFixture()))->score(new EvalSubject(
        output: ['text' => 'good'],
        input: ['prompt' => 'write something good'],
    ));

    expect($score->score)->toBe(1.0)
        ->and($score->metadata['prompt'])->toBe('write something good');
})->group('real-node');

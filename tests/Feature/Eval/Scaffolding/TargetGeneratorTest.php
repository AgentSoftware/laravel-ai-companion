<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerEntry;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\TargetGenerator;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\LlmJudgeScorer;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\FixtureAgent;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\NoConstructorFixtureAgent;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\ObjectParamFixtureAgent;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\ScalarDefaultsFixtureAgent;

it('renders an eval target with reflection-mapped constructor args', function (): void {
    $code = new TargetGenerator()->generate(
        namespace: 'App\\Ai\\Eval\\Targets',
        class: 'FixtureAgentEvalTarget',
        agentClass: FixtureAgent::class,
        key: 'fixture-agent',
        label: 'Fixture Agent',
        datasetPath: 'database/eval-datasets/fixture-agent.json',
        scorers: [new ScorerEntry(
            code: "new LlmJudgeScorer(name: 'quality', rubric: 'Is it good?')",
            imports: [LlmJudgeScorer::class],
        )],
    );

    expect($code)
        ->toContain('namespace App\\Ai\\Eval\\Targets;')
        ->toContain('final class FixtureAgentEvalTarget implements EvalTarget')
        ->toContain("return 'fixture-agent';")
        ->toContain("return 'Fixture Agent';")
        ->toContain("return 'database/eval-datasets/fixture-agent.json';")
        ->toContain("return (string) (\$row['prompt'] ?? '');")
        ->toContain('use '.LlmJudgeScorer::class.';')
        ->toContain("new LlmJudgeScorer(name: 'quality', rubric: 'Is it good?')")
        ->toContain('return new FixtureAgent(')
        ->toContain("companyBrandTone: (string) (\$row['company_brand_tone'] ?? '')")
        ->toContain("maxPages: (int) (\$row['max_pages'] ?? 5)");
});

it('emits container resolution with a TODO for object-typed params', function (): void {
    $code = new TargetGenerator()->generate(
        namespace: 'App\\Ai\\Eval\\Targets',
        class: 'AnonEvalTarget',
        agentClass: ObjectParamFixtureAgent::class,
        key: 'anon',
        label: 'Anon',
        datasetPath: 'database/eval-datasets/anon.json',
        scorers: [],
    );

    expect($code)->toContain('app(\\DateTimeInterface::class)')
        ->and($code)->toContain('TODO');
});

it('emits an empty arg list for agents with no constructor', function (): void {
    $code = new TargetGenerator()->generate(
        namespace: 'App\\Ai\\Eval\\Targets',
        class: 'NoConstructorEvalTarget',
        agentClass: NoConstructorFixtureAgent::class,
        key: 'no-ctor',
        label: 'No Ctor',
        datasetPath: 'database/eval-datasets/no-ctor.json',
        scorers: [],
    );

    expect($code)->toContain('return new NoConstructorFixtureAgent(');
});

it('falls back to cast-based defaults for scalar params without a default and a generic TODO for untyped params', function (): void {
    $code = new TargetGenerator()->generate(
        namespace: 'App\\Ai\\Eval\\Targets',
        class: 'ScalarDefaultsEvalTarget',
        agentClass: ScalarDefaultsFixtureAgent::class,
        key: 'scalar-defaults',
        label: 'Scalar Defaults',
        datasetPath: 'database/eval-datasets/scalar-defaults.json',
        scorers: [],
    );

    expect($code)->toContain("temperature: (float) (\$row['temperature'] ?? 0.0)")
        ->and($code)->toContain("verbose: (bool) (\$row['verbose'] ?? false)")
        ->and($code)->toContain("untyped: \$row['untyped'] ?? null /* TODO: map from a dataset row key */");
});

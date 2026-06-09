<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Evaluation\Judge\LlmJudge;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('instructions renders the blade view with the criteria prompt embedded', function (): void {
    $judge = new LlmJudge('Evaluate against these specific criteria: accuracy, tone');

    $instructions = $judge->instructions();

    expect($instructions)
        ->toContain('expert AI quality evaluator')
        ->toContain('Evaluate against these specific criteria: accuracy, tone')
        ->toContain('Score each criterion from 0 to 100');
});

it('schema declares required criteria array and summary fields', function (): void {
    $judge = new LlmJudge('test criteria');

    $result = $judge->schema(new JsonSchemaTypeFactory);

    expect($result)
        ->toBeArray()
        ->toHaveKey('criteria')
        ->toHaveKey('summary');
});

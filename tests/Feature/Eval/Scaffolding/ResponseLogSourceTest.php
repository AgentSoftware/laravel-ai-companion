<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ResponseLogSource;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;

it('maps response logs to dataset rows with expected and metadata', function (): void {
    AiResponseLog::create([
        'agent' => 'App\\Ai\\Agents\\PagePlannerAgent',
        'prompt' => 'Plan pages for acme.com',
        'response' => ['text' => 'Here is the plan'],
        'properties' => ['company_brand_tone' => 'friendly', 'nested' => ['drop' => 'me']],
        'metadata' => ['tag' => 'onboarding'],
        'status' => 'success',
    ]);

    $rows = new ResponseLogSource('App\\Ai\\Agents\\PagePlannerAgent')
        ->fetch(limit: 10, includeExpected: true, includeMetadata: true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['prompt'])->toBe('Plan pages for acme.com')
        ->and($rows[0]['expected'])->toBe(['text' => 'Here is the plan'])
        ->and($rows[0]['company_brand_tone'])->toBe('friendly')
        ->and($rows[0]['tag'])->toBe('onboarding')
        ->and($rows[0])->not->toHaveKey('nested');
});

it('filters by agent class and honours the limit and checkboxes', function (): void {
    AiResponseLog::create(['agent' => 'A', 'prompt' => 'one', 'response' => ['text' => 'x'], 'status' => 'success']);
    AiResponseLog::create(['agent' => 'B', 'prompt' => 'two', 'response' => ['text' => 'y'], 'status' => 'success']);

    $rows = new ResponseLogSource('A')->fetch(limit: 10, includeExpected: false, includeMetadata: false);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBe(['prompt' => 'one']);
});

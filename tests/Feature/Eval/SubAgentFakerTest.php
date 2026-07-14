<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Faking\PromptingAgentStack;
use AgentSoftware\LaravelAiCompanion\Eval\Faking\SubAgentFaker;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\StructuredSubAgentStub;

it('hands the sub-agent fake the live agent instance and flows its output', function (): void {
    $faker = new SubAgentFaker(app('events'), new PromptingAgentStack);

    // The fake reads the LIVE agent's typed input AND the prompt it was invoked
    // with — not just a fixed placeholder — proving it can simulate what was asked.
    $faker->install([
        StructuredSubAgentStub::class => fn (StructuredSubAgentStub $agent, string $prompt): array => [
            'echo' => $agent->value().':'.$prompt,
        ],
    ]);

    $response = StructuredSubAgentStub::make('typed-input')->prompt('the-request');

    expect($response->toArray())->toBe(['echo' => 'typed-input:the-request']);
});

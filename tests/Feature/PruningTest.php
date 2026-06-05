<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Illuminate\Support\Facades\Artisan;

it('prunes rows older than the configured window', function () {
    config(['ai-companion.response_logs.prune_after_months' => 6]);

    $old = AiResponseLog::create([
        'agent' => 'App\\Ai\\Agents\\StaleAgent',
        'prompt' => 'old prompt',
        'status' => AiResponseStatus::Success,
    ]);
    $old->forceFill(['created_at' => now()->subMonths(7), 'updated_at' => now()->subMonths(7)])->save();

    $recent = AiResponseLog::create([
        'agent' => 'App\\Ai\\Agents\\FreshAgent',
        'prompt' => 'recent prompt',
        'status' => AiResponseStatus::Success,
    ]);

    Artisan::call('model:prune', ['--model' => [AiResponseLog::class]]);

    expect(AiResponseLog::find($old->id))->toBeNull()
        ->and(AiResponseLog::find($recent->id))->not->toBeNull();
});

it('honours a custom prune window from config', function () {
    config(['ai-companion.response_logs.prune_after_months' => 1]);

    $borderline = AiResponseLog::create([
        'agent' => 'App\\Ai\\Agents\\BorderlineAgent',
        'prompt' => 'borderline',
        'status' => AiResponseStatus::Success,
    ]);
    $borderline->forceFill(['created_at' => now()->subMonths(2), 'updated_at' => now()->subMonths(2)])->save();

    Artisan::call('model:prune', ['--model' => [AiResponseLog::class]]);

    expect(AiResponseLog::find($borderline->id))->toBeNull();
});

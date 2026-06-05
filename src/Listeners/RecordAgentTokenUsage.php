<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Listeners;

use AgentSoftware\LaravelAiCompanion\Models\AiTokenUsage;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\AgentPrompted;

readonly class RecordAgentTokenUsage
{
    public function handle(AgentPrompted $event): void
    {
        $usage = $event->response->usage;

        AiTokenUsage::create([
            'agent' => get_class($event->prompt->agent),
            'model' => $event->prompt->model,
            'input_tokens' => $usage->promptTokens,
            'output_tokens' => $usage->completionTokens,
            'cache_write_tokens' => $usage->cacheWriteInputTokens,
            'cache_read_tokens' => $usage->cacheReadInputTokens,
            'source_id' => Context::get('ai_usage_source_id'),
            'source_model' => Context::get('ai_usage_source_model'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Feature;

use AgentSoftware\LaravelAiCompanion\Models\AiTokenUsage;
use AgentSoftware\LaravelAiCompanion\Tests\TestCase;

class TokenUsageDisabledProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-companion.token_usage.enabled', false);
    }

    public function test_it_records_nothing_when_token_usage_tracking_is_disabled(): void
    {
        event(makeTracingPromptedEvent());

        $this->assertSame(0, AiTokenUsage::count());
    }
}

<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Feature;

use AgentSoftware\LaravelAiCompanion\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\ToolInvoked;

class ToolCallLogsEnabledProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-companion.tool_call_logs.enabled', true);
    }

    public function test_it_subscribes_tool_call_logging_when_enabled(): void
    {
        $this->assertTrue(Event::hasListeners(ToolInvoked::class));
    }
}

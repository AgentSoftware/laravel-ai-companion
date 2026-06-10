<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Feature\Tracing;

use AgentSoftware\LaravelAiCompanion\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\PromptingAgent;

class TracingEnabledProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-companion.braintrust.enabled', true);
    }

    public function test_it_subscribes_tracing_listeners_when_braintrust_is_enabled(): void
    {
        $this->assertTrue(Event::hasListeners(PromptingAgent::class));
    }
}

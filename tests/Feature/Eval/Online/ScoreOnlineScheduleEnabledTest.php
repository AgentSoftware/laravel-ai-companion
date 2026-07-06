<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Feature\Eval\Online;

use AgentSoftware\LaravelAiCompanion\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

class ScoreOnlineScheduleEnabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-companion.eval.online.enabled', true);
    }

    public function test_it_registers_the_schedule_when_enabled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'ai:score-online'));

        $this->assertCount(1, $events);
    }
}

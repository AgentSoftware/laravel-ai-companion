<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion;

use AgentSoftware\LaravelAiCompanion\Listeners\RecordAgentTokenUsage;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelAiCompanionServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-ai-companion')
            ->hasConfigFile('ai-companion')
            ->hasMigrations([
                'create_ai_token_usages_table',
                'add_source_model_prompt_response_to_ai_token_usages_table',
                'drop_prompt_response_from_ai_token_usages_table',
                'create_ai_response_logs_table',
            ]);
    }

    public function packageBooted(): void
    {
        Event::listen(AgentPrompted::class, RecordAgentTokenUsage::class);

        $this->app->singleton(TokenUsageRepository::class);

        if (config('ai-companion.response_logs.prune_enabled')) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('model:prune', ['--model' => AiResponseLog::class])
                    ->cron(config('ai-companion.response_logs.prune_schedule', '0 3 * * *'));
            });
        }
    }
}

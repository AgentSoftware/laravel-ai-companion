<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion;

use AgentSoftware\LaravelAiCompanion\Console\EvaluateCommand;
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
            ->discoversMigrations()
            ->hasCommands([
                EvaluateCommand::class,
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

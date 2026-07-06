<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion;

use AgentSoftware\LaravelAiCompanion\Eval\Commands\ScaffoldEvalCommand;
use AgentSoftware\LaravelAiCompanion\Eval\Commands\ScoreOnlineCommand;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\Exporters\ExperimentExporterManager;
use AgentSoftware\LaravelAiCompanion\Listeners\RecordAgentTokenUsage;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Exporters\TraceExporterManager;
use AgentSoftware\LaravelAiCompanion\Tracing\Listeners\ExportTrace;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
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
            ->hasCommands(ScaffoldEvalCommand::class, ScoreOnlineCommand::class)
            ->discoversMigrations();
    }

    public function packageBooted(): void
    {
        Event::listen(AgentPrompted::class, RecordAgentTokenUsage::class);

        $this->app->singleton(TokenUsageRepository::class);

        $this->app->singleton(TraceTimings::class);

        $this->app->singleton(TraceExporterManager::class);

        $this->app->bind(
            TraceExporter::class,
            fn (Application $app): TraceExporter => $app->make(TraceExporterManager::class)->driver(),
        );

        $this->app->singleton(ExperimentExporterManager::class);

        $this->app->bind(
            ExperimentExporter::class,
            fn (Application $app): ExperimentExporter => $app->make(ExperimentExporterManager::class)->driver(),
        );

        if (config('ai-companion.braintrust.enabled')) {
            Event::subscribe(ExportTrace::class);
        }

        if (config('ai-companion.response_logs.prune_enabled')) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('model:prune', ['--model' => AiResponseLog::class])
                    ->cron(config('ai-companion.response_logs.prune_schedule', '0 3 * * *'));
            });
        }

        if (config('ai-companion.eval.online.enabled')) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('ai:score-online')
                    ->cron((string) config('ai-companion.eval.online.schedule', '*/15 * * * *'))
                    ->withoutOverlapping();
            });
        }
    }
}

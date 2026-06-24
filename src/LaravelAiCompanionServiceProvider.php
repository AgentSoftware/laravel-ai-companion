<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\Exporters\BraintrustExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Listeners\RecordAgentTokenUsage;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Tracing\Contracts\TraceExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Exporters\BraintrustExporter;
use AgentSoftware\LaravelAiCompanion\Tracing\Listeners\ExportTrace;
use AgentSoftware\LaravelAiCompanion\Tracing\TraceTimings;
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
            ->discoversMigrations();
    }

    public function packageBooted(): void
    {
        Event::listen(AgentPrompted::class, RecordAgentTokenUsage::class);

        $this->app->singleton(TokenUsageRepository::class);

        $this->app->singleton(TraceTimings::class);

        $this->app->bind(TraceExporter::class, BraintrustExporter::class);

        $this->app->bind(ExperimentExporter::class, BraintrustExperimentExporter::class);

        if (config('ai-companion.braintrust.enabled')) {
            Event::subscribe(ExportTrace::class);
        }

        if (config('ai-companion.response_logs.prune_enabled')) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('model:prune', ['--model' => AiResponseLog::class])
                    ->cron(config('ai-companion.response_logs.prune_schedule', '0 3 * * *'));
            });
        }
    }
}

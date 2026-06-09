<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion;

use AgentSoftware\LaravelAiCompanion\Console\EvaluateCommand;
use AgentSoftware\LaravelAiCompanion\Http\Middleware\Authorize;
use AgentSoftware\LaravelAiCompanion\Listeners\RecordAgentTokenUsage;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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
            ->hasViews()
            ->discoversMigrations()
            ->hasCommands([
                EvaluateCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        Event::listen(AgentPrompted::class, RecordAgentTokenUsage::class);

        $this->app->singleton(TokenUsageRepository::class);

        Gate::define('viewAiCompanion', function ($user = null) {
            return app()->environment('local');
        });

        if (config('ai-companion.dashboard.enabled', true)) {
            $middleware = array_merge(
                config('ai-companion.dashboard.middleware', ['web']),
                [Authorize::class]
            );
            $path = config('ai-companion.dashboard.path', 'ai-companion');

            Route::middleware($middleware)
                ->prefix($path)
                ->name('ai-companion.')
                ->group(__DIR__.'/../routes/web.php');
        }

        if (config('ai-companion.response_logs.prune_enabled')) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('model:prune', ['--model' => AiResponseLog::class])
                    ->cron(config('ai-companion.response_logs.prune_schedule', '0 3 * * *'));
            });
        }
    }
}

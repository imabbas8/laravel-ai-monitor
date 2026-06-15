<?php

namespace Debug\AiHealth;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Debug\AiHealth\Commands\PackageHealthCommand;
use Debug\AiHealth\Services\AiExplanationService;
use Debug\AiHealth\Services\AiProviderFactory;
use Debug\AiHealth\Services\GitHubService;
use Debug\AiHealth\Services\HealthScoreService;
use Debug\AiHealth\Services\PackagistService;

class AiHealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-health.php', 'ai-health');

        $this->app->singleton(PackagistService::class, function ($app) {
            return new PackagistService($app->make(HttpFactory::class), $app['config']['ai-health']);
        });

        $this->app->singleton(GitHubService::class, function ($app) {
            return new GitHubService($app->make(HttpFactory::class), $app['config']['ai-health']);
        });

        $this->app->singleton(HealthScoreService::class, function ($app) {
            return new HealthScoreService($app['config']['ai-health']);
        });

        $this->app->singleton(AiProviderFactory::class, function ($app) {
            return new AiProviderFactory($app->make(HttpFactory::class), $app['config']['ai-health']['ai']);
        });

        $this->app->singleton(AiExplanationService::class, function ($app) {
            return new AiExplanationService(
                $app->make(AiProviderFactory::class),
                $app['config']['ai-health']['ai']
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ai-health.php' => $this->app->configPath('ai-health.php'),
            ], 'ai-health-config');

            $this->commands([
                PackageHealthCommand::class,
            ]);
        }
    }
}

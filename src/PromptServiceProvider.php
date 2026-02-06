<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Support\ServiceProvider;

class PromptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/prism-prompt.php',
            'prism-prompt'
        );

        $this->app->singleton(PerformanceLogger::class, function () {
            return new PerformanceLogger;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/prism-prompt.php' => config_path('prism-prompt.php'),
            ], 'prism-prompt-config');
        }
    }
}

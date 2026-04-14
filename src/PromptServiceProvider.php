<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Listeners\PerformanceDebugFileListener;
use Kent013\PrismPrompt\Listeners\PerformanceLogListener;

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

        if (config('prism-prompt.debug.enabled')) {
            Event::listen(PromptExecutionCompleted::class, PerformanceLogListener::class);
        }

        if (config('prism-prompt.debug.save_files')) {
            Event::listen(PromptExecutionCompleted::class, PerformanceDebugFileListener::class);
        }
    }
}

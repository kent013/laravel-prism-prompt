<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Listeners\PerformanceDebugFileListener;
use Kent013\PrismPrompt\Listeners\PerformanceLogListener;
use Kent013\PrismPrompt\Operation\Console\PruneJobsCommand;
use Kent013\PrismPrompt\Operation\Listeners\ResolvePendingLlmCallReferences;
use Kent013\PrismPrompt\Pricing\LlmPricingService;

class PromptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/prism-prompt.php',
            'prism-prompt'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../config/prism-prompt-pricing.php',
            'prism-prompt-pricing'
        );

        $this->app->singleton(PerformanceLogger::class, function () {
            return new PerformanceLogger;
        });

        $this->app->singleton(LlmPricingService::class, function () {
            return new LlmPricingService;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/prism-prompt.php' => config_path('prism-prompt.php'),
            ], 'prism-prompt-config');

            $this->publishes([
                __DIR__.'/../config/prism-prompt-pricing.php' => config_path('prism-prompt-pricing.php'),
            ], 'prism-prompt-pricing');

            // Operation Job migration を opt-in 時のみ load
            if (config('prism-prompt.jobs.enabled', true)) {
                $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
                $this->commands([PruneJobsCommand::class]);
            }
        }

        if (config('prism-prompt.debug.enabled')) {
            Event::listen(PromptExecutionCompleted::class, PerformanceLogListener::class);
        }

        if (config('prism-prompt.debug.save_files')) {
            Event::listen(PromptExecutionCompleted::class, PerformanceDebugFileListener::class);
        }

        // Operation Job: pending llm_call resolution listener
        if (config('prism-prompt.jobs.enabled', true)) {
            Event::listen(PromptExecutionCompleted::class, ResolvePendingLlmCallReferences::class);
        }
    }
}

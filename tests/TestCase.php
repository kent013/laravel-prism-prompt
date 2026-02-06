<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Tests;

use Kent013\PrismPrompt\PromptServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PromptServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('prism-prompt.prompts_path', __DIR__.'/fixtures/prompts');
        $app['config']->set('prism-prompt.cache.enabled', false);
    }
}

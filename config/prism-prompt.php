<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The default LLM provider to use when not specified in the prompt class
    | or YAML template.
    |
    */
    'default_provider' => env('PRISM_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | The default model to use when not specified in the prompt class
    | or YAML template.
    |
    */
    'default_model' => env('PRISM_MODEL', 'claude-sonnet-4-5-20250929'),

    /*
    |--------------------------------------------------------------------------
    | Default Max Tokens
    |--------------------------------------------------------------------------
    |
    | The default maximum number of tokens for LLM responses.
    |
    */
    'default_max_tokens' => 4096,

    /*
    |--------------------------------------------------------------------------
    | Default Temperature
    |--------------------------------------------------------------------------
    |
    | The default temperature for LLM responses (0.0 - 1.0).
    |
    */
    'default_temperature' => 0.7,

    /*
    |--------------------------------------------------------------------------
    | Prompts Path
    |--------------------------------------------------------------------------
    |
    | The base path where prompt template files are stored.
    | Templates can only be loaded from within this directory for security.
    |
    */
    'prompts_path' => resource_path('prompts'),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for caching parsed YAML templates.
    | Enabled by default in production for performance.
    | Set PRISM_PROMPT_CACHE=false in .env for development.
    |
    */
    'cache' => [
        'enabled' => env('PRISM_PROMPT_CACHE', true),
        'ttl' => 3600,
        'store' => null, // null = default cache driver
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for debug logging and file saving.
    | Useful for debugging LLM prompts and responses.
    |
    */
    'debug' => [
        'enabled' => env('PRISM_PROMPT_DEBUG', false),
        'log_channel' => env('PRISM_PROMPT_LOG_CHANNEL', 'prism-prompt'),
        'save_files' => env('PRISM_PROMPT_SAVE_FILES', false),
        'storage_path' => storage_path('prism-prompt-debug'),
    ],
];

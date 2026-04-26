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
    | Default Embedding Provider
    |--------------------------------------------------------------------------
    |
    | The default provider for embedding generation.
    | Separate from text provider since not all providers support embeddings.
    |
    */
    'default_embedding_provider' => env('PRISM_EMBEDDING_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Default Embedding Model
    |--------------------------------------------------------------------------
    |
    | The default model for embedding generation.
    |
    */
    'default_embedding_model' => env('PRISM_EMBEDDING_MODEL', 'text-embedding-3-small'),

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
    | Prompt Pool Settings
    |--------------------------------------------------------------------------
    |
    | Concurrency for Kent013\PrismPrompt\PromptPool::executeWithWarmup().
    | When null, the pool fans out to all remaining prompts in a single chunk
    | after warmup (i.e. no cap). Set this to a positive integer to throttle
    | concurrent HTTP requests per chunk. Individual callers may override via
    | the $concurrency argument.
    |
    */
    'pool' => [
        'concurrency' => env('PRISM_PROMPT_POOL_CONCURRENCY'),
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

    /*
    |--------------------------------------------------------------------------
    | Operation Job Settings (opt-in)
    |--------------------------------------------------------------------------
    |
    | LLM 呼び出しを含む operation を妨害 (リロード/失敗/プロセスクラッシュ/
    | 2 タブ並行) に対して堅牢化する Job 基盤。`enabled=false` にすると migration
    | は実行されず、PromptOperation も無効化される (ヒント: 既存ユーザは false で
    | 起動して機能を opt-in する)。
    |
    */
    'jobs' => [
        // opt-in: 既存ユーザーへの影響を防ぐため default は false。
        // PromptOperation を使う場合は env で true にするか config を override する。
        // Codex Round 1 Critical 反映: enabled=true が default だと
        // ResolvePendingLlmCallReferences listener が常時 llm_call_logs を直接参照し、
        // 当該テーブル未作成の既存ユーザーで実行時エラー化するため。
        'enabled' => env('PRISM_PROMPT_JOBS_ENABLED', false),
        'table_prefix' => env('PRISM_PROMPT_JOBS_TABLE_PREFIX', 'prism_prompt_'),
        'llm_call_logs_table' => env('PRISM_PROMPT_JOBS_LLM_CALL_LOGS_TABLE', 'llm_call_logs'),
        'default_heartbeat_ttl_seconds' => env('PRISM_PROMPT_JOBS_HEARTBEAT_TTL', 90),
        'follower_poll_intervals_ms' => [250, 500, 1000, 2000, 2000, 2000],
        'follower_max_wait_seconds' => env('PRISM_PROMPT_JOBS_FOLLOWER_MAX_WAIT', 120),
        'retention' => [
            'completed_days' => env('PRISM_PROMPT_JOBS_RETAIN_COMPLETED_DAYS', 30),
            'failed_days' => env('PRISM_PROMPT_JOBS_RETAIN_FAILED_DAYS', 90),
            'cancelled_days' => env('PRISM_PROMPT_JOBS_RETAIN_CANCELLED_DAYS', 90),
        ],
    ],
];

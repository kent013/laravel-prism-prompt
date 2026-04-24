<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Values;

/**
 * Cache breakpoint types for prompt section caching.
 *
 * Used with Prompt::withCacheBreakpoints() to mark YAML sections
 * whose content should be cached by the provider (e.g. Anthropic
 * prompt caching via `cache_control: {type: ephemeral}` blocks).
 *
 * String values match provider API values verbatim so that callers
 * can serialize directly without mapping.
 */
enum CacheType: string
{
    case Ephemeral = 'ephemeral';
}

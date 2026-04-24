<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when Prompt::withCacheBreakpoints() receives a section name
 * that is not declared under the `sections:` key of the prompt YAML.
 */
final class InvalidCacheBreakpointException extends InvalidArgumentException {}

<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Exceptions;

use Prism\Prism\Exceptions\PrismException;

/**
 * The provider declined the request (finish reason `refusal`), whether or not any text came back.
 *
 * Extends Prism's PrismException because a refusal is a provider-side failure: callers that
 * already catch PrismException (or Throwable) route it into their existing failure handling.
 * The message carries the prompt class only, never the provider's response text.
 */
final class PromptRefusedException extends PrismException
{
    /**
     * @param  class-string  $promptClass
     */
    public function __construct(public readonly string $promptClass)
    {
        parent::__construct("The provider refused the request for {$promptClass}");
    }
}

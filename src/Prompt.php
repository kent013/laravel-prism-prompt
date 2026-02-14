<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Kent013\PrismPrompt\Contracts\PromptInterface;
use Kent013\PrismPrompt\Exceptions\InvalidJsonResponseException;
use Kent013\PrismPrompt\Testing\PromptFake;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Kent013\PrismPrompt\Traits\ResolvesProviderConfig;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use React\Promise\PromiseInterface;
use RuntimeException;
use Webmozart\Assert\Assert;

use function React\Async\async;

/**
 * Base class for LLM prompts (Mailable-like API)
 *
 * Settings priority (high to low):
 * - Class property
 * - YAML template
 * - Config default
 *
 * @template TResponse
 *
 * @implements PromptInterface<TResponse>
 */
abstract class Prompt implements PromptInterface
{
    use ResolvesProviderConfig;

    protected static ?PromptFake $fake = null;

    protected ?int $maxTokens = null;

    protected ?float $temperature = null;

    /** @var array<string, mixed> */
    protected array $templateVariables = [];

    public function __construct()
    {
        $this->loadMetadata();
    }

    /**
     * Create a TextPrompt instance from YAML template name
     *
     * @param  array<string, mixed>  $variables
     *
     * @return TextPrompt
     */
    public static function load(string $name, array $variables = []): self
    {
        $basePath = config('prism-prompt.prompts_path', resource_path('prompts'));
        Assert::string($basePath);

        $instance = new TextPrompt;
        $instance->templatePath = $basePath.'/'.$name.'.yaml';
        $instance->templateVariables = $variables;
        $instance->loadMetadata();

        return $instance;
    }

    /**
     * Start faking prompt executions for testing
     *
     * @param  array<int, TextResponseFake>  $responses
     */
    public static function fake(array $responses = []): PromptFake
    {
        static::$fake = new PromptFake($responses);

        return static::$fake;
    }

    /**
     * Get the current fake instance
     */
    public static function getFake(): ?PromptFake
    {
        return static::$fake;
    }

    /**
     * Check if currently faking
     */
    public static function isFaking(): bool
    {
        return static::$fake !== null;
    }

    /**
     * Stop faking and restore normal behavior
     */
    public static function stopFaking(): void
    {
        static::$fake = null;
    }

    /**
     * Execute prompt asynchronously and return DTO
     *
     * @return PromiseInterface<TResponse>
     */
    public function execute(): PromiseInterface
    {
        return async(function (): mixed {
            $responseText = $this->executePrism();

            return $this->parseResponse($responseText);
        })();
    }

    /**
     * Execute prompt synchronously and return DTO
     *
     * @return TResponse
     */
    public function executeSync(): mixed
    {
        $responseText = $this->executePrism();

        return $this->parseResponse($responseText);
    }

    /**
     * Parse response text into DTO (implement in each Prompt subclass)
     *
     * @return TResponse
     */
    abstract protected function parseResponse(string $responseText): mixed;

    /**
     * Extract JSON from LLM response
     *
     * @return array<string, mixed>
     *
     * @throws InvalidJsonResponseException
     */
    protected function extractJson(string $content): array
    {
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = $matches[0];
        } else {
            throw new InvalidJsonResponseException('No JSON found in response');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            Assert::isArray($data);

            /** @var array<string, mixed> $data */
            return $data;
        } catch (JsonException $e) {
            Log::error('[PrismPrompt] JSON parse error', [
                'content' => $content,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidJsonResponseException(
                'Failed to parse JSON: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Render Blade template with variables (prompt field)
     */
    protected function render(): string
    {
        $promptTemplate = $this->metadata['prompt'] ?? '';
        Assert::stringNotEmpty($promptTemplate, 'Prompt template is empty in metadata');

        return Blade::render($promptTemplate, $this->resolveTemplateVariables());
    }

    /**
     * Render system_prompt field from YAML
     */
    protected function renderSystemPrompt(): ?string
    {
        $template = $this->metadata['system_prompt'] ?? null;
        if (! is_string($template) || $template === '') {
            return null;
        }

        return Blade::render($template, $this->resolveTemplateVariables());
    }

    /**
     * Resolve template variables for Blade rendering
     *
     * @return array<string, mixed>
     */
    protected function resolveTemplateVariables(): array
    {
        return $this->templateVariables !== [] ? $this->templateVariables : get_object_vars($this);
    }

    /**
     * Build message array for Prism
     *
     * Override this for complete control over the message structure.
     *
     * @return array<int, Message>
     */
    protected function buildMessages(): array
    {
        $messages = [];

        $system = $this->buildSystemMessage();
        if ($system !== null) {
            $messages[] = $system;
        }

        $messages = array_merge($messages, $this->buildConversationMessages());

        $this->validateMessages($messages);

        return $messages;
    }

    /**
     * Build system message from YAML system_prompt
     *
     * Override this to customize the system message.
     */
    protected function buildSystemMessage(): ?SystemMessage
    {
        $content = $this->renderSystemPrompt();

        return $content !== null ? new SystemMessage($content) : null;
    }

    /**
     * Build conversation messages (user/assistant)
     *
     * Override this to provide custom message structure (e.g. conversation history).
     *
     * @return array<int, Message>
     */
    protected function buildConversationMessages(): array
    {
        return [new UserMessage($this->render())];
    }

    /**
     * Validate message array constraints
     *
     * @param  array<int, Message>  $messages
     */
    protected function validateMessages(array $messages): void
    {
        if ($messages === []) {
            throw new RuntimeException('Messages array cannot be empty');
        }

        $last = end($messages);
        if (! $last instanceof UserMessage) {
            throw new InvalidArgumentException('Last message must be a UserMessage');
        }

        $systemCount = 0;
        $systemIndex = -1;
        foreach ($messages as $index => $msg) {
            if ($msg instanceof SystemMessage) {
                $systemCount++;
                $systemIndex = $index;
            }
        }

        if ($systemCount > 1) {
            throw new InvalidArgumentException('Multiple SystemMessages are not allowed');
        }

        if ($systemCount === 1 && $systemIndex !== 0) {
            throw new InvalidArgumentException('SystemMessage must be the first message');
        }
    }

    /**
     * Execute Prism LLM call
     */
    protected function executePrism(): string
    {
        $messages = $this->buildMessages();
        $selected = $this->selectOptimalProvider();

        $provider = $selected['provider'];
        $model = $selected['model'];
        $config = $selected['config'];

        if (static::isFaking() && static::$fake !== null) {
            static::$fake->record(static::class, $messages, $provider, $model);
            $fakeResponse = static::$fake->nextResponse();

            return $fakeResponse->getText();
        }

        $logger = $this->getPerformanceLogger();
        $promptForLog = $this->messagesToString($messages);
        $executionId = $logger?->startExecution(static::class, $provider, $model, $promptForLog);
        $startTime = microtime(true);

        $result = Prism::text()
            ->using($provider, $model, $config)
            ->withMessages($messages)
            ->withMaxTokens($this->resolveMaxTokens())
            ->asText();

        Assert::isInstanceOf($result, TextResponse::class);

        if ($logger && $executionId) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $logger->completeExecution(
                $executionId,
                $result->text,
                $durationMs,
                $result->usage->promptTokens,
                $result->usage->completionTokens
            );
        }

        return $result->text;
    }

    /**
     * Get the performance logger instance
     */
    protected function getPerformanceLogger(): ?Contracts\PerformanceLoggerInterface
    {
        if (! app()->bound(PerformanceLogger::class)) {
            return null;
        }

        $logger = app(PerformanceLogger::class);

        return $logger->isEnabled() ? $logger : null;
    }

    /**
     * Resolve max tokens (class property > YAML > config)
     */
    protected function resolveMaxTokens(): int
    {
        if ($this->maxTokens !== null) {
            return $this->maxTokens;
        }

        $yamlMaxTokens = $this->metadata['max_tokens'] ?? null;
        if (is_int($yamlMaxTokens)) {
            return $yamlMaxTokens;
        }

        $configMaxTokens = config('prism-prompt.default_max_tokens', 4096);
        Assert::integer($configMaxTokens);

        return $configMaxTokens;
    }

    /**
     * Resolve temperature (class property > YAML > config)
     */
    protected function resolveTemperature(): float
    {
        if ($this->temperature !== null) {
            return $this->temperature;
        }

        $yamlTemperature = $this->metadata['temperature'] ?? null;
        if (is_numeric($yamlTemperature)) {
            return (float) $yamlTemperature;
        }

        $configTemperature = config('prism-prompt.default_temperature', 0.7);
        Assert::numeric($configTemperature);

        return (float) $configTemperature;
    }

    /**
     * Convert message array to string for logging
     *
     * @param  array<int, Message>  $messages
     */
    private function messagesToString(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            $role = match (true) {
                $msg instanceof SystemMessage => 'system',
                $msg instanceof UserMessage => 'user',
                $msg instanceof AssistantMessage => 'assistant',
                default => 'unknown',
            };
            $content = $msg->content;
            $truncated = mb_substr($content, 0, 200);
            $parts[] = "[{$role}] {$truncated}".(mb_strlen($content) > 200 ? '...' : '');
        }

        return implode("\n", $parts);
    }
}

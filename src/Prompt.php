<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Kent013\PrismPrompt\Contracts\PromptInterface;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;
use Kent013\PrismPrompt\Exceptions\InvalidJsonResponseException;
use Kent013\PrismPrompt\Testing\PromptFake;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Kent013\PrismPrompt\Traits\ResolvesProviderConfig;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderTool;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
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

    /** @var array<int, ProviderTool> */
    protected array $providerTools = [];

    protected ?int $maxSteps = null;

    /** @var array<string, mixed> */
    protected array $clientOptions = [];

    /** @var array<string, mixed> */
    protected array $templateVariables = [];

    /** @var array<string, mixed> */
    protected array $metadata_context = [];

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
     * Set provider tools (e.g. web_search)
     *
     * @param  array<int, ProviderTool>  $providerTools
     */
    public function withProviderTools(array $providerTools): static
    {
        $this->providerTools = $providerTools;

        return $this;
    }

    /**
     * Set max steps for multi-step tool use
     */
    public function withMaxSteps(int $maxSteps): static
    {
        $this->maxSteps = $maxSteps;

        return $this;
    }

    /**
     * Set client options (e.g. timeout)
     *
     * @param  array<string, mixed>  $options
     */
    public function withClientOptions(array $options): static
    {
        $this->clientOptions = $options;

        return $this;
    }

    /**
     * Attach caller-side context metadata that flows through to events.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata_context = array_merge($this->metadata_context, $metadata);

        return $this;
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
     * Extract prompt template name from templatePath.
     */
    private function extractPromptTemplate(): ?string
    {
        if ($this->templatePath === '') {
            return null;
        }

        $basename = basename($this->templatePath, '.yaml');

        return $basename === '' ? null : $basename;
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

        $executionId = (string) Str::uuid();
        $startTime = microtime(true);

        // Separate SystemMessage for Anthropic compatibility
        $systemPrompt = null;
        $nonSystemMessages = [];
        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                $systemPrompt = $message->content;
            } else {
                $nonSystemMessages[] = $message;
            }
        }

        $builder = Prism::text()
            ->using($provider, $model, $config)
            ->withMaxTokens($this->resolveMaxTokens());

        if ($systemPrompt !== null) {
            $builder->withSystemPrompt($systemPrompt);
        }

        if ($nonSystemMessages !== []) {
            $builder->withMessages($nonSystemMessages);
        }

        $resolvedProviderTools = $this->resolveProviderTools();
        if ($resolvedProviderTools !== []) {
            $builder->withProviderTools($resolvedProviderTools);
        }

        $resolvedMaxSteps = $this->resolveMaxSteps();
        if ($resolvedMaxSteps !== null) {
            $builder->withMaxSteps($resolvedMaxSteps);
        }

        $resolvedClientOptions = $this->resolveClientOptions();
        if ($resolvedClientOptions !== []) {
            $builder->withClientOptions($resolvedClientOptions);
        }

        try {
            $result = $builder->asText();
            Assert::isInstanceOf($result, TextResponse::class);

            $durationMs = (microtime(true) - $startTime) * 1000;

            try {
                event(new PromptExecutionCompleted(
                    executionId: $executionId,
                    promptClass: static::class,
                    promptTemplate: $this->extractPromptTemplate(),
                    provider: $provider,
                    model: $model,
                    finishReason: $result->finishReason,
                    stepCount: $result->steps->count(),
                    totalUsage: $result->usage,
                    durationMs: $durationMs,
                    requestId: $result->meta->id ?? null,
                    response: $result,
                    metadata: $this->metadata_context,
                ));
            } catch (Throwable $eventError) {
                Log::error('Failed to dispatch PromptExecutionCompleted event', [
                    'execution_id' => $executionId,
                    'error' => $eventError->getMessage(),
                ]);
            }

            return $result->text;
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;

            try {
                event(new PromptExecutionFailed(
                    executionId: $executionId,
                    promptClass: static::class,
                    promptTemplate: $this->extractPromptTemplate(),
                    provider: $provider,
                    model: $model,
                    durationMs: $durationMs,
                    exception: $e,
                    metadata: $this->metadata_context,
                ));
            } catch (Throwable $eventError) {
                Log::error('Failed to dispatch PromptExecutionFailed event', [
                    'execution_id' => $executionId,
                    'original_error' => $e->getMessage(),
                    'dispatch_error' => $eventError->getMessage(),
                ]);
            }

            throw $e;
        }
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
     * Resolve provider tools (class property > YAML > empty)
     *
     * @return array<int, ProviderTool>
     */
    protected function resolveProviderTools(): array
    {
        if ($this->providerTools !== []) {
            return $this->providerTools;
        }

        $yamlTools = $this->metadata['provider_tools'] ?? null;
        if (! is_array($yamlTools)) {
            return [];
        }

        $tools = [];
        foreach ($yamlTools as $tool) {
            if (! is_array($tool) || ! isset($tool['type'])) {
                continue;
            }
            Assert::string($tool['type']);
            $name = isset($tool['name']) && is_string($tool['name']) ? $tool['name'] : null;
            /** @var array<string, mixed> $options */
            $options = isset($tool['options']) && is_array($tool['options']) ? $tool['options'] : [];
            $tools[] = new ProviderTool($tool['type'], $name, $options);
        }

        return $tools;
    }

    /**
     * Resolve max steps (class property > YAML > null)
     */
    protected function resolveMaxSteps(): ?int
    {
        if ($this->maxSteps !== null) {
            return $this->maxSteps;
        }

        $yamlMaxSteps = $this->metadata['max_steps'] ?? null;
        if (is_int($yamlMaxSteps)) {
            return $yamlMaxSteps;
        }

        return null;
    }

    /**
     * Resolve client options (class property > YAML > empty)
     *
     * @return array<string, mixed>
     */
    protected function resolveClientOptions(): array
    {
        if ($this->clientOptions !== []) {
            return $this->clientOptions;
        }

        $yamlOptions = $this->metadata['client_options'] ?? null;
        if (is_array($yamlOptions)) {
            /** @var array<string, mixed> $yamlOptions */
            return $yamlOptions;
        }

        return [];
    }
}

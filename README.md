# Laravel Prism Prompt

Laravel Mailable-like API for LLM prompts with [Prism](https://github.com/echolabsdev/prism).

## Installation

```bash
composer require because/laravel-prism-prompt
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=prism-prompt-config
```

### Settings Priority

Settings are resolved in the following priority (high to low):

1. Class property
2. YAML template
3. Config default

## Usage

### 1. Create a YAML Template

```yaml
# resources/prompts/greeting.yaml
name: greeting
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 1024
temperature: 0.7

prompt: |
  Say hello to {{ $userName }}.
  Return JSON: {"message": "...", "tone": "..."}
```

### 2. Create a Prompt Class

```php
use Because\PrismPrompt\Prompt;

class GreetingPrompt extends Prompt
{
    public function __construct(
        public readonly string $userName,
    ) {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return resource_path('prompts/greeting.yaml');
    }

    protected function parseResponse(string $text): GreetingResponse
    {
        $data = $this->extractJson($text);
        return new GreetingResponse($data['message'], $data['tone']);
    }
}
```

### 3. Execute

```php
// Synchronous
$result = (new GreetingPrompt('Alice'))->executeSync();

// Asynchronous (requires react/promise)
$promise = (new GreetingPrompt('Alice'))->execute();
```

## Response Parsing

### JSON Response

```php
protected function parseResponse(string $text): SomeDto
{
    $data = $this->extractJson($text);
    return new SomeDto($data);
}
```

### Plain Text Response

```php
protected function parseResponse(string $text): string
{
    return trim($text);
}
```

## Traits

### LoadsPromptTemplate

For loading templates with fallback resolution:

```php
use Because\PrismPrompt\Traits\LoadsPromptTemplate;

class MyService
{
    use LoadsPromptTemplate;

    public function process(array $prompts): void
    {
        // Tries: prompts/{$prompts['greeting']}.yaml, then prompts/common/greeting.yaml
        $template = $this->loadTemplate($prompts, 'greeting');
    }
}
```

### ValidatesPromptVariables

For validating required variables:

```php
use Because\PrismPrompt\Traits\ValidatesPromptVariables;

class MyService
{
    use ValidatesPromptVariables;

    public function process(PromptTemplate $template, array $variables): void
    {
        $this->validateVariables($variables, $template);
    }
}
```

## License

MIT

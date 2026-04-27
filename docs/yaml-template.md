# YAML Template Reference

A prompt is defined by a YAML file. The file declares model settings, the
`system_prompt` (instructions, role) and the `prompt` (dynamic data, task).
Both are Blade templates with full access to the variables you pass at
runtime.

## Where the YAML lives

YAML is resolved in the following priority:

1. **`$promptName` property** — relative path from `prompts_path`
2. **Naming convention** — derived from class name (`GreetingPrompt` → `greeting.yaml`)
3. **`$promptsDirectory`** — group prompts in a subdirectory

```php
// resources/prompts/standard/greeting.yaml
class GreetingPrompt extends Prompt
{
    protected string $promptName = 'standard/greeting';
}

// resources/prompts/greeting.yaml — auto-derived from class name
class GreetingPrompt extends Prompt {}

// resources/prompts/training/hint_generation.yaml
class HintGenerationPrompt extends Prompt
{
    protected string $promptsDirectory = 'training';
}
```

`getTemplatePath()` is overridable for full path control.

## Settings priority

When the same setting appears in multiple places, higher wins:

1. Class property (e.g. `protected ?float $temperature = 0.5`)
2. YAML field (e.g. `temperature: 0.7`)
3. Config default (`config('prism-prompt.default_temperature')`)

## Basic fields

| Field | Required | Description |
|-------|----------|-------------|
| `name` | No | Template name (informational) |
| `version` | No | Template version (informational) |
| `description` | No | Template description (informational) |
| `provider` | No | Default LLM provider (`anthropic`, `openai`, `google`, …) |
| `model` | No | Default model name |
| `max_tokens` | No | Maximum tokens in response |
| `temperature` | No | Response randomness (0.0 - 1.0) |
| `system_prompt` | No | Blade template for the system-role message |
| `prompt` | Yes | Blade template for the user-role message |
| `models` | No | List of fallback models (see [providers.md](providers.md)) |
| `sections` | No | Named text fragments for prompt caching (see [parallel-execution.md](parallel-execution.md)) |
| `meta` | No | Free-form application metadata |

## Message structure

`system_prompt` and `prompt` are dispatched as separate Prism messages:

| Role | Source |
|------|--------|
| `SystemMessage` | Rendered `system_prompt` |
| `UserMessage` | Rendered `prompt` |

If `system_prompt` is omitted, only a `UserMessage` is sent (backward
compatible). The same template variables are available to both.

```yaml
system_prompt: |
  You are {{ $npcName }}, a {{ $npcRole }}.
  Always respond in character.

prompt: |
  {{ $conversationHistory }}

  User: {{ $userMessage }}
```

## Customising the message array

Three override points let you control message construction at increasing
breadth. Pick the narrowest one that fits.

| Method | Scope | Default behavior |
|--------|-------|------------------|
| `buildSystemMessage()` | System message only | Renders `system_prompt` from YAML |
| `buildConversationMessages()` | User/assistant messages | Returns `[new UserMessage($this->render())]` |
| `buildMessages()` | Full message array | Calls the two above in order |

```php
class ChatPrompt extends Prompt
{
    /** @return array<int, Message> */
    protected function buildConversationMessages(): array
    {
        $messages = [];
        foreach ($this->history as $msg) {
            $messages[] = match ($msg->role) {
                'user' => new UserMessage($msg->content),
                'assistant' => new AssistantMessage($msg->content),
            };
        }
        $messages[] = new UserMessage($this->render());

        return $messages;
    }
}
```

See [`examples/03-conversation-history.php`](../examples/03-conversation-history.php)
and [`examples/11-chatbot-with-defense.php`](../examples/11-chatbot-with-defense.php)
for full runnable code.

## Meta section

Free-form metadata for your application. The package itself does not
interpret it.

```yaml
meta:
  variables:
    runtime:
      - userName
      - npcName
```

## Complete example

```yaml
name: generate_greeting
version: 1.0.0
description: Generate personalized greeting message

provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 500
temperature: 0.8

models:
  - { provider: anthropic, model: claude-sonnet-4-5-20250929, priority: 1 }
  - { provider: openai, model: gpt-4o, priority: 2 }

system_prompt: |
  You are a professional greeter for {{ $scenarioTitle }}.
  Always respond in JSON format with "message" and "tone" fields.

prompt: |
  Generate a greeting for {{ $userName }} ({{ $userRole }}).
```

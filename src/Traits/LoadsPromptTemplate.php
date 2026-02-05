<?php

declare(strict_types=1);

namespace Because\PrismPrompt\Traits;

use Because\PrismPrompt\Exceptions\InvalidTemplatePathException;
use Because\PrismPrompt\Exceptions\PromptTemplateNotFoundException;
use Because\PrismPrompt\PromptTemplate;

/**
 * YAML loading and path resolution via prompts array or common fallback
 *
 * Resolution order:
 * 1. Path specified in prompts array (resources/prompts/{path}.yaml)
 * 2. Common (resources/prompts/common/{promptName}.yaml)
 * 3. Error if not found
 *
 * @param  array<string, string>  $prompts  Scenario's prompts configuration array
 */
trait LoadsPromptTemplate
{
    /**
     * @param  array<string, string>  $prompts
     */
    protected function loadTemplate(array $prompts, string $promptName): PromptTemplate
    {
        $path = $this->resolveTemplatePath($prompts, $promptName);
        $this->validateTemplatePath($path);

        return PromptTemplate::fromYaml($path);
    }

    /**
     * @param  array<string, string>  $prompts
     */
    protected function resolveTemplatePath(array $prompts, string $promptName): string
    {
        $basePath = $this->getPromptsBasePath();

        // 1. Check path specified in prompts array
        if (isset($prompts[$promptName])) {
            $customPath = $basePath."/{$prompts[$promptName]}.yaml";
            if (file_exists($customPath)) {
                return $customPath;
            }
        }

        // 2. Check common
        $commonPath = $basePath."/common/{$promptName}.yaml";
        if (file_exists($commonPath)) {
            return $commonPath;
        }

        // 3. Error if not found
        throw new PromptTemplateNotFoundException(
            "Prompt template not found: {$promptName}"
        );
    }

    /**
     * Validate that template path is within allowed base path
     */
    protected function validateTemplatePath(string $path): void
    {
        $basePath = $this->getPromptsBasePath();
        $realBasePath = realpath($basePath);

        // Base path itself does not exist
        if ($realBasePath === false) {
            throw new InvalidTemplatePathException(
                "Prompts base path does not exist: {$basePath}"
            );
        }

        $realPath = realpath($path);

        // Template file does not exist or is outside allowed path
        if ($realPath === false || ! str_starts_with($realPath, $realBasePath)) {
            throw new InvalidTemplatePathException(
                "Template path must be within: {$basePath}"
            );
        }
    }

    /**
     * Get the base path for prompts
     */
    protected function getPromptsBasePath(): string
    {
        $path = config('prism-prompt.prompts_path');

        return is_string($path) ? $path : resource_path('prompts');
    }
}

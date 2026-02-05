<?php

declare(strict_types=1);

use Because\PrismPrompt\Exceptions\InvalidTemplatePathException;
use Because\PrismPrompt\Exceptions\MissingPromptVariablesException;
use Because\PrismPrompt\Exceptions\PromptTemplateNotFoundException;
use Because\PrismPrompt\PromptTemplate;
use Because\PrismPrompt\Traits\LoadsPromptTemplate;
use Because\PrismPrompt\Traits\ValidatesPromptVariables;

// Test class using LoadsPromptTemplate
class TemplateLoader
{
    use LoadsPromptTemplate;

    public function load(array $prompts, string $promptName): PromptTemplate
    {
        return $this->loadTemplate($prompts, $promptName);
    }

    public function testValidatePath(string $path): void
    {
        $this->validateTemplatePath($path);
    }
}

// Test class using ValidatesPromptVariables
class VariableValidator
{
    use ValidatesPromptVariables;

    public function validate(array $variables, PromptTemplate $template): void
    {
        $this->validateVariables($variables, $template);
    }
}

describe('LoadsPromptTemplate', function (): void {
    it('loads template from common path', function (): void {
        $loader = new TemplateLoader();

        $template = $loader->load([], 'greeting');

        expect($template->name)->toBe('greeting');
    });

    it('throws exception for non-existent template', function (): void {
        $loader = new TemplateLoader();

        $loader->load([], 'non_existent');
    })->throws(PromptTemplateNotFoundException::class);

    it('throws exception for path outside base directory', function (): void {
        $loader = new TemplateLoader();

        $loader->testValidatePath('/etc/passwd');
    })->throws(InvalidTemplatePathException::class);
});

describe('ValidatesPromptVariables', function (): void {
    it('validates required variables', function (): void {
        $validator = new VariableValidator();
        $template = PromptTemplate::fromYaml(__DIR__.'/../fixtures/prompts/common/greeting.yaml');

        $validator->validate(['userName' => 'Alice'], $template);

        expect(true)->toBeTrue(); // No exception thrown
    });

    it('throws exception for missing variables', function (): void {
        $validator = new VariableValidator();
        $template = PromptTemplate::fromYaml(__DIR__.'/../fixtures/prompts/common/greeting.yaml');

        $validator->validate([], $template);
    })->throws(MissingPromptVariablesException::class, 'Missing variables: userName');
});

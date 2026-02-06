<?php

declare(strict_types=1);

use Kent013\PrismPrompt\Exceptions\MissingPromptVariablesException;
use Kent013\PrismPrompt\PromptTemplate;
use Kent013\PrismPrompt\Traits\ValidatesPromptVariables;

// Test class using ValidatesPromptVariables
class VariableValidator
{
    use ValidatesPromptVariables;

    public function validate(array $variables, PromptTemplate $template): void
    {
        $this->validateVariables($variables, $template);
    }
}

describe('ValidatesPromptVariables', function (): void {
    it('validates required variables', function (): void {
        $validator = new VariableValidator;
        $template = PromptTemplate::fromYaml(__DIR__.'/../fixtures/prompts/common/greeting.yaml');

        $validator->validate(['userName' => 'Alice'], $template);

        expect(true)->toBeTrue(); // No exception thrown
    });

    it('throws exception for missing variables', function (): void {
        $validator = new VariableValidator;
        $template = PromptTemplate::fromYaml(__DIR__.'/../fixtures/prompts/common/greeting.yaml');

        $validator->validate([], $template);
    })->throws(MissingPromptVariablesException::class, 'Missing variables: userName');
});

<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Traits;

use Kent013\PrismPrompt\Exceptions\MissingPromptVariablesException;
use Kent013\PrismPrompt\PromptTemplate;

/**
 * Validates required variables
 *
 * @phpstan-type VariableDefinition array{name: string, path?: string, type?: string, description?: string}|string
 */
trait ValidatesPromptVariables
{
    /**
     * Validate that all required variables are present
     *
     * @param  array<string, mixed>  $variables
     */
    protected function validateVariables(array $variables, PromptTemplate $template): void
    {
        $allRequired = array_merge(
            $this->extractVariableNames($template->getFromScenarioVariables()),
            $this->extractVariableNames($template->getRuntimeVariables())
        );

        $missing = array_diff($allRequired, array_keys($variables));

        if ($missing !== []) {
            throw new MissingPromptVariablesException(
                'Missing variables: '.implode(', ', $missing)
            );
        }
    }

    /**
     * Extract variable names from definitions
     *
     * @param  array<int, VariableDefinition>  $defs
     * @return array<int, string>
     */
    private function extractVariableNames(array $defs): array
    {
        return collect($defs)->map(fn ($def): string => is_array($def) ? $def['name'] : $def
        )->values()->all();
    }
}

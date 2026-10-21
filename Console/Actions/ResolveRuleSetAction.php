<?php

namespace Studeo\Modular\Console\Actions;

use Error;
use Studeo\Check\Engine\RuleSet;
use Studeo\Check\Exceptions\InvalidRuleConfigurationException as BaseInvalidRuleConfigurationException;
use Studeo\Modular\Check\DefaultRules;
use Studeo\Modular\Exceptions\InvalidRuleConfigurationException;

/**
 * Builds the rule set named by config modular.check.rules. Only module:check calls it, so a
 * mistake in the configured class can never break any other command.
 */
final class ResolveRuleSetAction
{
    /**
     * @throws InvalidRuleConfigurationException for a class that is missing, is not a rule set, or whose options are rejected
     */
    public function execute(): RuleSet
    {
        $class = (string) config('modular.check.rules', DefaultRules::class);
        if (! class_exists($class)) {
            throw InvalidRuleConfigurationException::missingClass($class);
        }

        return $this->rulesOf($class);
    }

    private function rulesOf(string $class): RuleSet
    {
        /** @var object $provider */
        $provider = app()->make($class);
        if (! method_exists($provider, 'rules')) {
            throw InvalidRuleConfigurationException::notARuleSet($class);
        }

        try {
            $rules = $provider->rules();
        } catch (Error|BaseInvalidRuleConfigurationException $error) {
            throw InvalidRuleConfigurationException::rejected($class, $error->getMessage());
        }

        return $this->assertRuleSet($rules, $class);
    }

    private function assertRuleSet(mixed $rules, string $class): RuleSet
    {
        if (! $rules instanceof RuleSet) {
            throw InvalidRuleConfigurationException::notARuleSet($class);
        }

        return $rules;
    }
}

<?php

namespace Studeo\Modular\Exceptions;

use Studeo\Check\Exceptions\InvalidRuleConfigurationException as BaseException;

final class InvalidRuleConfigurationException extends BaseException
{
    public static function missingClass(string $class): self
    {
        return new self("The rule set class {$class} set in config modular.check.rules does not exist. Point it at a class that extends Studeo\\Modular\\Check\\DefaultRules.");
    }

    public static function notARuleSet(string $class): self
    {
        return new self("The rule set class {$class} set in config modular.check.rules must have a rules() method that returns a Studeo\\Check\\Engine\\RuleSet.");
    }

    public static function rejected(string $class, string $reason): self
    {
        return new self("The rule set class {$class} could not be built: {$reason}");
    }
}

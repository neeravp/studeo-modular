<?php

namespace Studeo\Modular\Tests\CheckRules;

use LogicException;
use Studeo\Check\Engine\RuleSet;
use Studeo\Modular\Check\DefaultRules;

final class Explodes extends DefaultRules
{
    public function rules(): RuleSet
    {
        throw new LogicException('boom from the rule set');
    }
}

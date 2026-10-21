<?php

namespace Studeo\Modular\Tests\CheckRules;

use Studeo\Check\Engine\RuleSet;
use Studeo\Check\Rules\FileLength;
use Studeo\Modular\Check\DefaultRules;

final class BadOptions extends DefaultRules
{
    public function rules(): RuleSet
    {
        return RuleSet::of(FileLength::options(target: 500, hard: 300));
    }
}

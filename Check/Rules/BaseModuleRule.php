<?php

namespace Studeo\Modular\Check\Rules;

use LogicException;
use Studeo\Check\Engine\Rule;
use Studeo\Check\Engine\SourceSet;
use Studeo\Check\Engine\Violation;
use Studeo\Modular\Check\ModuleSource;

abstract class BaseModuleRule implements Rule
{
    /**
     * @return list<Violation>
     */
    final public function violations(SourceSet $sources): array
    {
        if (! $sources instanceof ModuleSource) {
            throw new LogicException(static::class.' requires a ModuleSource instance.');
        }

        return $this->moduleViolations($sources);
    }

    /**
     * @return list<Violation>
     */
    abstract public function moduleViolations(ModuleSource $module): array;
}

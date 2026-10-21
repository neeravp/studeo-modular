<?php

namespace Studeo\Modular\Check\Rules;

use LogicException;
use Studeo\Check\Engine\SourceFile;
use Studeo\Check\Engine\SourceSet;
use Studeo\Check\Engine\Violation;
use Studeo\Check\Rules\FileRule;
use Studeo\Modular\Check\ModuleSource;

abstract class ModuleFileRule extends FileRule
{
    /**
     * @return list<Violation>
     */
    final protected function inspect(SourceFile $file, SourceSet $sources): array
    {
        if (! $sources instanceof ModuleSource) {
            throw new LogicException(static::class.' requires a ModuleSource instance.');
        }

        return $this->inspectInModule($file, $sources);
    }

    /**
     * @return list<Violation>
     */
    abstract protected function inspectInModule(SourceFile $file, ModuleSource $module): array;
}

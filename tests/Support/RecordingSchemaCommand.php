<?php

namespace Studeo\Modular\Tests\Support;

use Closure;
use Override;
use Studeo\Modular\Console\ModuleSchemaCommand;

/**
 * module:schema, except that the check handed to resolveCheckedName is kept, wrapped, so that a test
 * can see when it runs and what it throws.
 */
final class RecordingSchemaCommand extends ModuleSchemaCommand
{
    /** @var array<int, mixed> */
    public array $log = [];

    public ?Closure $check = null;

    #[Override]
    protected function promptValidator(Closure $check): Closure
    {
        $this->check = $check;
        $this->log[] = 'validator built';
        $validator = parent::promptValidator($check);

        return function (string $value) use ($validator): ?string {
            $this->log[] = ['validated', $value];

            return $validator($value);
        };
    }
}

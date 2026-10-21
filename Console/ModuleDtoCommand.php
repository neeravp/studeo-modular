<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateDtoAction;
use Studeo\Modular\Console\Actions\ResolveDtoKindAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Throwable;

class ModuleDtoCommand extends ModuleCommand
{
    protected $signature = 'module:dto
        {name? : The DTO name; it must end in Input (request data) or Data (response data)}
        {--module= : The name of the module}
        {--schema= : A schema (@name, a file path, or inline) to take the properties from}';

    protected $aliases = ['module:data'];

    protected $description = 'Create a new Input or Output data object in a module; the name suffix decides which';

    public function handle(CreateDtoAction $creator, ResolveDtoKindAction $kinds, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveDtoName($kinds);

            $path = $creator->execute($module, $name, $this->stringOption('schema'));
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $dtoName = basename($path, '.php');
        $this->displayCreated('DTO', $dtoName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveDtoName(ResolveDtoKindAction $kinds): string
    {
        return $this->resolveCheckedName(
            label: 'What should the DTO be named?',
            placeholder: 'e.g. CreatePostInput, PostData',
            check: $kinds->execute(...),
            missingMessage: "The DTO name is required when the command is not interactive. The name must end in Input or Data. {$kinds->forms()}",
        );
    }
}

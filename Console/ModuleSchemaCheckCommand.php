<?php

namespace Studeo\Modular\Console;

use InvalidArgumentException;
use Studeo\Modular\Console\Actions\CheckSchemaDriftAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\InvalidSchemaException;
use Studeo\Modular\Support\Schema\SchemaFiles;
use Throwable;

class ModuleSchemaCheckCommand extends ModuleCommand
{
    protected $signature = 'module:schema:check
        {name? : The model name; every schema in the module is checked when omitted}
        {--module= : The name of the module}
        {--except= : Comma-separated sections to skip: model, migration, factory, dto}';

    protected $description = 'Check that the files generated from a schema still match it';

    /**
     * Exit 0 when nothing differs, 1 for drift or a section that cannot be verified, 2 when a
     * schema is invalid or missing (the issues are printed). Warnings never change the code.
     */
    public function handle(CheckSchemaDriftAction $checker, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));
            $tables = $this->tables($module);
            $except = $this->except();
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        if ($tables === []) {
            $this->error("No schema files found in module [{$module->name}].");

            return self::FAILURE;
        }

        return max(array_map(fn (string $table): int => $this->check($checker, $module, $table, $except), $tables));
    }

    /**
     * @return array<int, string>
     */
    private function tables(ModuleDefinition $module): array
    {
        $name = $this->stringArgument('name');

        return $name === null ? SchemaFiles::tables($module) : [SchemaFiles::tableFor((string) $name)];
    }

    /**
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    private function except(): array
    {
        $given = array_values(array_filter(array_map('trim', explode(',', (string) $this->stringOption('except'))), fn (string $section): bool => $section !== ''));
        $unknown = array_values(array_diff($given, CheckSchemaDriftAction::SECTIONS));

        return $unknown === []
            ? $given
            : throw new InvalidArgumentException('Unknown --except value ['.implode(', ', $unknown).']. Valid: '.implode(', ', CheckSchemaDriftAction::SECTIONS).'.');
    }

    /**
     * @param  array<int, string>  $except
     */
    private function check(CheckSchemaDriftAction $checker, ModuleDefinition $module, string $table, array $except): int
    {
        try {
            $differences = $checker->execute($module, $table, $except);
        } catch (InvalidSchemaException $exception) {
            $this->error($exception->getMessage());

            return 2;
        }

        array_map($this->line(...), $differences);

        $this->line($differences === []
            ? "Schema [{$table}]: no drift."
            : "Schema [{$table}]: ".count($differences).' '.(count($differences) === 1 ? 'difference.' : 'differences.'));

        return $differences === [] ? self::SUCCESS : self::FAILURE;
    }
}

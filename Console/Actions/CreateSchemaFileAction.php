<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\DataObjects\RawSchema;
use Studeo\Modular\Exceptions\InvalidSchemaException;
use Studeo\Modular\Support\Schema\SchemaFiles;
use Studeo\Modular\Support\Schema\SchemaYamlWriter;

/**
 * Scaffolds schema/{table}.yaml for a model: from the compact fields string when there is one,
 * otherwise the smallest valid schema. The text is validated before anything is written, and an
 * existing file is never overwritten.
 */
final class CreateSchemaFileAction
{
    public function __construct(
        private readonly ParseCliSchemaAction $cliParser,
        private readonly ParseSchemaAction $schemaParser,
        private readonly SchemaYamlWriter $writer,
        private readonly NameSanitizer $sanitizer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    /**
     * @throws InvalidSchemaException when what would be written is not a valid schema
     */
    public function execute(ModuleDefinition $module, string $name, ?string $fields = null): string
    {
        $this->moduleGuard->ensureExists($module);

        $model = $this->sanitizer->studlyName($name, 'model');
        $table = SchemaFiles::tableFor($model);
        $path = SchemaFiles::path($module, $table);

        $this->artifactWriter->ensureDoesNotExist($path, 'Schema', $table, $module);

        $yaml = $this->dumpValidatedSchema($module, $fields, $table, $model);

        $this->artifactWriter->write($path, $yaml);

        return $path;
    }

    /**
     * @throws InvalidSchemaException when the text is not a valid schema for the module
     */
    private function dumpValidatedSchema(ModuleDefinition $module, ?string $fields, string $table, string $model): string
    {
        $yaml = $this->writer->dump($this->raw($fields), $table, $model);
        $this->schemaParser->execute($yaml, $module);

        return $yaml;
    }

    private function raw(?string $fields): RawSchema
    {
        return $fields === null || trim($fields) === ''
            ? new RawSchema(fields: [new RawField('name')])
            : $this->cliParser->read($fields);
    }
}

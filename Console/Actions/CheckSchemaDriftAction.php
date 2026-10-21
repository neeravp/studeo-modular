<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\DtoKind;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Exceptions\InvalidSchemaException;
use Studeo\Modular\Support\FakerDefinitionResolver;
use Studeo\Modular\Support\MigrationColumnResolver;
use Studeo\Modular\Support\ModelStubContent;
use Studeo\Modular\Support\Schema\DtoProperties;
use Studeo\Modular\Support\Schema\ForeignKeyFaker;
use Studeo\Modular\Support\Schema\ModuleModels;
use Studeo\Modular\Support\Schema\PhpSectionReader;
use Studeo\Modular\Support\SchemaWarnings;

/**
 * Compares a schema file with what was generated from it, section by section: the model's
 * $fillable and casts(), the create migration's columns and the factory's definition keys. It
 * reads only those sections, so code added anywhere else in a file is never drift. A name on one
 * side only, or a different rendered definition, is a difference; a file or section it cannot
 * read is reported as unverifiable and counts the same. Alter migrations are not compared.
 */
final class CheckSchemaDriftAction
{
    public const array SECTIONS = ['model', 'migration', 'factory', 'dto'];

    private const array TIMESTAMP_CASTS = ['created_at' => "'immutable_datetime'", 'updated_at' => "'immutable_datetime'"];

    public function __construct(
        private readonly ParseSchemaAction $parser,
        private readonly PhpSectionReader $reader,
        private readonly SchemaWarnings $warnings,
        private readonly ModuleModels $models,
    ) {}

    /**
     * One line per difference, grouped by file; empty when the schema and its files agree.
     *
     * @param  array<int, string>  $except  sections to skip: neither compared nor reported as unverifiable
     * @return array<int, string>
     *
     * @throws InvalidSchemaException when the schema file is missing or invalid
     */
    public function execute(ModuleDefinition $module, string $table, array $except = []): array
    {
        $schema = $this->parser->execute("@{$table}", $module)->forModule($module->namespace);
        $model = $schema->name ?? Str::studly(Str::singular($table));

        return [
            ...(in_array('model', $except, true) ? [] : $this->model($module, $schema, $model)),
            ...(in_array('migration', $except, true) ? [] : $this->migration($module, $schema, $table)),
            ...(in_array('factory', $except, true) ? [] : $this->factory($module, $schema, $model)),
            ...(in_array('dto', $except, true) ? [] : $this->dtos($module, $schema, $model)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function model(ModuleDefinition $module, SchemaDefinition $schema, string $model): array
    {
        $file = "Models/{$model}.php";
        $source = $this->source($module, $file);

        return [
            ...$this->compareNames($file, 'fillable', ModelStubContent::fillableFields($schema), $source === null ? null : $this->reader->fillable($source)),
            ...$this->compareMap($file, 'casts', array_map(DtoProperties::shorten(...), [...self::TIMESTAMP_CASTS, ...ModelStubContent::castExpressions($schema)]), $source === null ? null : $this->shortened($this->reader->casts($source))),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function migration(ModuleDefinition $module, SchemaDefinition $schema, string $table): array
    {
        $matches = glob($module->path."/database/migrations/*_create_{$table}_table.php") ?: [];
        sort($matches);
        $this->warnMultiple($table, $matches);
        $file = $matches === [] ? "database/migrations/*_create_{$table}_table.php" : 'database/migrations/'.basename(end($matches));
        $source = $matches === [] ? null : $this->source($module, $file);
        $expected = array_map(fn ($field): string => $this->reader->normalize(MigrationColumnResolver::resolve($field)), $schema->fields);

        return $this->compareMap($file, 'columns', $expected, $source === null ? null : $this->reader->migrationColumns($source));
    }

    /**
     * @param  array<int, string>  $matches
     */
    private function warnMultiple(string $table, array $matches): void
    {
        if (count($matches) > 1) {
            $names = implode(', ', array_map(fn (string $path): string => 'database/migrations/'.basename($path), $matches));
            $this->warnings->add(SchemaIssue::warning($table, 'multiple_create_migrations', "several create migrations exist for [{$table}]: {$names}; the last by name is checked."));
        }
    }

    /**
     * @return array<int, string>
     */
    private function factory(ModuleDefinition $module, SchemaDefinition $schema, string $model): array
    {
        $file = "database/factories/{$model}Factory.php";
        $source = $this->source($module, $file);

        $definition = FakerDefinitionResolver::definition($schema, new ForeignKeyFaker($this->models, $module, config('auth.providers.users.model')));
        $found = $source === null ? null : $this->reader->factoryKeys($source);

        return $this->compareNames($file, 'definition', array_keys($definition->attributes), $found === null ? null : array_values(array_diff($found, array_keys($definition->omitted))));
    }

    /**
     * The Input and the Output the schema would generate, when they exist; one that does not exist
     * is not checked, one that cannot be read is unverifiable. Order is never compared.
     *
     * @return array<int, string>
     */
    private function dtos(ModuleDefinition $module, SchemaDefinition $schema, string $model): array
    {
        $differences = [];

        foreach ([DtoKind::Input, DtoKind::Output] as $kind) {
            $file = "Data/{$model}{$kind->value}.php";
            $source = $this->source($module, $file);
            $found = $source === null ? null : $this->reader->constructorParameters($source);

            $differences = [...$differences, ...($source === null ? [] : $this->compareMap(
                $file,
                'properties',
                DtoProperties::expected($schema, $kind),
                $found === null ? null : array_map(DtoProperties::shorten(...), $found),
            ))];
        }

        return $differences;
    }

    /**
     * Casts with their namespaces removed, so an imported enum equals its fully qualified spelling.
     *
     * @param  array<string, string>|null  $casts
     * @return array<string, string>|null
     */
    private function shortened(?array $casts): ?array
    {
        return $casts === null ? null : array_map(DtoProperties::shorten(...), $casts);
    }

    private function source(ModuleDefinition $module, string $file): ?string
    {
        $path = "{$module->path}/{$file}";

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>|null  $found
     * @return array<int, string>
     */
    private function compareNames(string $file, string $section, array $expected, ?array $found): array
    {
        return $found === null
            ? [$this->unverifiable($file, $section)]
            : $this->onlyOneSide($file, $section, $expected, $found);
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>|null  $found
     * @return array<int, string>
     */
    private function compareMap(string $file, string $section, array $expected, ?array $found): array
    {
        if ($found === null) {
            return [$this->unverifiable($file, $section)];
        }

        $differing = array_filter(array_keys(array_intersect_key($expected, $found)), fn (string $name): bool => $expected[$name] !== $found[$name]);

        return [
            ...$this->onlyOneSide($file, $section, array_keys($expected), array_keys($found)),
            ...array_map(fn (string $name): string => "{$file} {$section} [{$name}] differs: schema ".rtrim($expected[$name], ';').'; file '.rtrim($found[$name], ';'), array_values($differing)),
        ];
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $found
     * @return array<int, string>
     */
    private function onlyOneSide(string $file, string $section, array $expected, array $found): array
    {
        $missing = array_values(array_diff($expected, $found));
        $extra = array_values(array_diff($found, $expected));

        return [
            ...($missing === [] ? [] : ["{$file} {$section} schema has [".implode(', ', $missing).']; file lacks it']),
            ...($extra === [] ? [] : ["{$file} {$section} file has [".implode(', ', $extra).']; schema lacks it']),
        ];
    }

    private function unverifiable(string $file, string $section): string
    {
        return "{$file} {$section} unverifiable: the file or the {$section} section could not be read";
    }
}

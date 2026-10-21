<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use JsonException;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\RawSchema;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Exceptions\InvalidSchemaException;
use Studeo\Modular\Support\Schema\SchemaFiles;
use Studeo\Modular\Support\Schema\SchemaValidator;
use Studeo\Modular\Support\SchemaWarnings;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Turns a schema (a CLI string, inline JSON or YAML, or a file path) into a SchemaDefinition.
 * Every schema is read to a RawSchema and validated first; all the issues found come back in
 * one InvalidSchemaException, and warnings are kept for the command to print.
 */
class ParseSchemaAction
{
    public function __construct(
        private readonly ParseStructuredSchemaAction $structuredParser = new ParseStructuredSchemaAction,
        private readonly ParseCliSchemaAction $cliParser = new ParseCliSchemaAction,
        private readonly SchemaValidator $validator = new SchemaValidator,
        private readonly BuildSchemaDefinitionAction $builder = new BuildSchemaDefinitionAction,
        private readonly SchemaWarnings $warnings = new SchemaWarnings,
    ) {}

    /**
     * The module is optional: without one, the rules that read the module (enum files,
     * relationship targets) are skipped.
     *
     * @throws InvalidSchemaException
     */
    public function execute(?string $input, ?ModuleDefinition $module = null): SchemaDefinition
    {
        if ($this->isBlank($input)) {
            return new SchemaDefinition;
        }

        $raw = $this->read(trim($input), $module);
        $issues = $this->validator->validate($raw, $module);

        if (array_filter($issues, fn (SchemaIssue $issue): bool => $issue->isError()) !== []) {
            throw new InvalidSchemaException($issues);
        }

        foreach ($issues as $warning) {
            $this->warnings->add($warning);
        }

        return $this->builder->schema($raw);
    }

    /**
     * The text is JSON when it starts with a brace or bracket, YAML when it holds a newline and a colon
     * followed by a space (a single line is always the CLI form), and the compact CLI form otherwise; a file's extension decides when it has one
     * of json, yaml or yml. Text that is clearly JSON or YAML but will not parse is one syntax issue.
     *
     * @throws InvalidSchemaException
     */
    private function read(string $input, ?ModuleDefinition $module): RawSchema
    {
        $input = str_starts_with($input, '@') ? $this->referencedFile($input, $module) : $input;
        $isFile = $this->isSchemaFile($input);
        $content = $isFile ? $this->readFile($input) : $input;
        $extension = $isFile ? strtolower(pathinfo($input, PATHINFO_EXTENSION)) : '';
        $where = $isFile ? " in schema file [{$input}]" : '';

        return match (true) {
            $this->isJson($extension, $content) => $this->decoded('JSON', $where, fn (): mixed => json_decode($content, true, 512, JSON_THROW_ON_ERROR)),
            in_array($extension, ['yaml', 'yml'], true) => $this->decoded('YAML', $where, fn (): mixed => Yaml::parse($content)),
            $this->looksLikeYaml($content) => $this->sniffedYaml($content),
            default => $this->cliParser->read($content),
        };
    }

    /**
     * "@posts" names schema/posts.yaml in the module.
     *
     * @throws InvalidSchemaException
     */
    private function referencedFile(string $reference, ?ModuleDefinition $module): string
    {
        $table = substr($reference, 1);

        return match (true) {
            preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1 => throw $this->syntax("Schema reference [{$reference}] must be @ followed by a lower snake case table name, e.g. @blog_posts."),
            $module === null => throw $this->syntax("Schema reference [{$reference}] needs a module: it names schema/{$table}.yaml in the module. Pass --module."),
            ! is_file(SchemaFiles::path($module, $table)) => throw $this->syntax('Schema ['.$reference.'] was not found: expected '.SchemaFiles::path($module, $table).'.'),
            default => SchemaFiles::path($module, $table),
        };
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);

        return $content === false ? throw $this->syntax("Unable to read schema file: {$path}") : trim($content);
    }

    /**
     * @param  callable(): mixed  $decode
     */
    private function decoded(string $format, string $where, callable $decode): RawSchema
    {
        try {
            $data = $decode();
        } catch (JsonException|ParseException $exception) {
            throw $this->syntax("Invalid {$format}{$where}: {$exception->getMessage()}", $exception);
        }

        return is_array($data) ? $this->structuredParser->read($data) : throw $this->syntax("Invalid {$format}{$where}: the schema must be a map.");
    }

    /**
     * Text with a colon and a space is YAML; if it parses to something other than a map it was
     * only a CLI string with a stray space, and is read as one.
     */
    private function sniffedYaml(string $content): RawSchema
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException $exception) {
            throw $this->syntax("Invalid YAML: {$exception->getMessage()}", $exception);
        }

        return is_array($data) ? $this->structuredParser->read($data) : $this->cliParser->read($content);
    }

    private function syntax(string $message, ?Throwable $previous = null): InvalidSchemaException
    {
        return new InvalidSchemaException([SchemaIssue::error('schema', 'schema_syntax', $message)], $previous);
    }

    /**
     * @phpstan-assert-if-false string $input
     */
    private function isBlank(?string $input): bool
    {
        return $input === null || trim($input) === '';
    }

    private function isSchemaFile(string $input): bool
    {
        return ! str_contains($input, "\n") && is_file($input);
    }

    private function isJson(string $extension, string $content): bool
    {
        return $extension === 'json' || ($extension !== 'yaml' && $extension !== 'yml' && Str::startsWith($content, ['{', '[']));
    }

    private function looksLikeYaml(string $content): bool
    {
        return str_contains($content, "\n") && str_contains($content, ': ');
    }
}

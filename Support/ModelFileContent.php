<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\SchemaDefinition;

/**
 * The text of a generated model: the Model stub filled from the schema, with every class it
 * names imported. The factory wiring (HasFactory, the factory import and newFactory()) appears
 * only when a factory class is given, so a model never references a factory that does not exist.
 */
final class ModelFileContent
{
    public static function render(string $stub, string $namespace, string $class, string $table, SchemaDefinition $schema, ?string $factoryClass): string
    {
        $imports = new PhpImports($namespace, $class);
        $model = $imports->name('Illuminate\\Database\\Eloquent\\Model');
        $hasFactory = $factoryClass === null ? null : $imports->name('Illuminate\\Database\\Eloquent\\Factories\\HasFactory');
        $factory = $factoryClass === null ? null : $imports->name($factoryClass);

        $replacements = [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class,
            '{{ model }}' => $model,
            '{{ table }}' => $table,
            '{{ carbon }}' => $imports->name('Carbon\\CarbonImmutable'),
            '{{ properties }}' => ModelStubContent::properties($schema, $namespace, $imports),
            '{{ fillable }}' => ModelStubContent::fillable($schema),
            '{{ casts }}' => ModelStubContent::casts($schema, $imports),
            '{{ traits }}' => $hasFactory === null ? '' : "    use {$hasFactory};\n\n",
            '{{ methods }}' => self::methods([...ModelStubContent::relationshipMethods($schema, $imports), ...self::newFactory($factory)]),
        ];

        return str_replace([...array_keys($replacements), '{{ imports }}'], [...array_values($replacements), $imports->statements()], $stub);
    }

    /**
     * @return array<int, string>
     */
    private static function newFactory(?string $factory): array
    {
        return $factory === null ? [] : [
            "    /**\n"
            ."     * Create a new factory instance for the model.\n"
            ."     */\n"
            ."    protected static function newFactory(): {$factory}\n"
            ."    {\n"
            ."        return {$factory}::new();\n"
            .'    }',
        ];
    }

    /**
     * Each method after a blank line, as Pint separates class members.
     *
     * @param  array<int, string>  $methods
     */
    private static function methods(array $methods): string
    {
        return implode('', array_map(fn (string $method): string => "\n{$method}\n", $methods));
    }
}

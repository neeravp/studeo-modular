<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\FactoryDefinition;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\Schema\ForeignKeyFaker;
use Studeo\Modular\Support\Schema\ForeignKeyTarget;

final class FakerDefinitionResolver
{
    private const array EXACT_NAME_FAKERS = [
        'name' => 'fake()->name()',
        'full_name' => 'fake()->name()',
        'first_name' => 'fake()->firstName()',
        'last_name' => 'fake()->lastName()',
        'email' => 'fake()->unique()->safeEmail()',
        'password' => "bcrypt('password')",
        'phone' => 'fake()->phoneNumber()',
        'phone_number' => 'fake()->phoneNumber()',
        'mobile' => 'fake()->phoneNumber()',
        'address' => 'fake()->streetAddress()',
        'street_address' => 'fake()->streetAddress()',
        'city' => 'fake()->city()',
        'country' => 'fake()->country()',
        'postal_code' => 'fake()->postcode()',
        'zip' => 'fake()->postcode()',
        'slug' => 'fake()->unique()->slug()',
        'url' => 'fake()->url()',
    ];

    private const array TYPE_FAKERS = [
        'text' => 'fake()->paragraph()',
        'mediumtext' => 'fake()->paragraph()',
        'longtext' => 'fake()->paragraph()',
        'boolean' => 'fake()->boolean()',
        'bool' => 'fake()->boolean()',
        'integer' => 'fake()->randomNumber()',
        'tinyinteger' => 'fake()->randomNumber()',
        'smallinteger' => 'fake()->randomNumber()',
        'mediuminteger' => 'fake()->randomNumber()',
        'biginteger' => 'fake()->randomNumber()',
        'unsignedinteger' => 'fake()->randomNumber()',
        'unsignedtinyinteger' => 'fake()->randomNumber()',
        'unsignedsmallinteger' => 'fake()->randomNumber()',
        'unsignedmediuminteger' => 'fake()->randomNumber()',
        'unsignedbiginteger' => 'fake()->randomNumber()',
        'decimal' => 'fake()->randomFloat(2, 10, 1000)',
        'float' => 'fake()->randomFloat(2, 10, 1000)',
        'double' => 'fake()->randomFloat(2, 10, 1000)',
        'date' => 'fake()->date()',
        'datetime' => 'fake()->dateTime()',
        'timestamp' => 'fake()->dateTime()',
        'json' => '[]',
        'jsonb' => '[]',
        'uuid' => 'fake()->uuid()',
        'ulid' => 'fake()->ulid()',
    ];

    /**
     * The factory's definition for every fillable field of the schema, by field name. A foreign key
     * is faked through its target's factory; one that has none to call is left out, with the reason.
     * Classes are named through the imports when given, fully qualified otherwise.
     */
    public static function definition(SchemaDefinition $schema, ModuleDefinition $module, ?string $userModel = null, ?PhpImports $imports = null): FactoryDefinition
    {
        $keys = new ForeignKeyFaker($module, $userModel, $imports);
        $fillable = array_values(array_filter($schema->fields, fn (FieldDefinition $field): bool => $field->isFillable()));
        $outcomes = array_combine(
            array_map(fn (FieldDefinition $field): string => $field->name, $fillable),
            array_map(fn (FieldDefinition $field): array => self::outcome($field, $keys, $imports), $fillable),
        );
        $attributes = array_map(fn (array $outcome): string => $outcome[0], array_filter($outcomes, fn (array $outcome): bool => $outcome[0] !== null));
        $omitted = array_map(fn (array $outcome): string => (string) $outcome[1], array_diff_key($outcomes, $attributes));

        return new FactoryDefinition($attributes, $omitted);
    }

    /**
     * @return array{0: ?string, 1: ?string} the expression, or the reason the field is left out
     */
    private static function outcome(FieldDefinition $field, ForeignKeyFaker $keys, ?PhpImports $imports): array
    {
        return ForeignKeyTarget::isForeignKey($field) ? $keys->resolve($field) : [self::resolve($field, $imports), null];
    }

    public static function resolve(FieldDefinition $field, ?PhpImports $imports = null): string
    {
        return self::resolveByName($field)
            ?? self::resolveByPattern($field)
            ?? self::resolveByEnum($field, $imports)
            ?? self::resolveByType($field);
    }

    private static function resolveByName(FieldDefinition $field): ?string
    {
        return self::EXACT_NAME_FAKERS[strtolower($field->name)] ?? null;
    }

    private static function resolveByPattern(FieldDefinition $field): ?string
    {
        $lowerName = strtolower($field->name);

        if (str_ends_with($lowerName, '_email')) {
            return 'fake()->unique()->safeEmail()';
        }

        if (str_ends_with($lowerName, '_url')) {
            return 'fake()->url()';
        }

        return null;
    }

    private static function resolveByEnum(FieldDefinition $field, ?PhpImports $imports): ?string
    {
        return $field->isEnum() ? 'fake()->randomElement('.($imports?->name($field->enumClass()) ?? "\\{$field->enumClass()}").'::cases())' : null;
    }

    private static function resolveByType(FieldDefinition $field): string
    {
        $type = strtolower($field->type);

        return match ($type) {
            'decimal' => self::resolveDecimalFaker($field),
            'float', 'double' => self::resolveFloatingPointFaker($field),
            default => self::TYPE_FAKERS[$type] ?? 'fake()->sentence()',
        };
    }

    /**
     * A decimal is a string in PHP: the declared scale, and a value inside the declared precision.
     */
    private static function resolveDecimalFaker(FieldDefinition $field): string
    {
        $precision = (int) ($field->typeArguments[0] ?? 8);
        $scale = (int) ($field->typeArguments[1] ?? 2);
        $integerDigits = max($precision - $scale, 0);
        $max = $integerDigits === 0 ? 0 : min(9999, 10 ** $integerDigits - 1);

        return "number_format(fake()->randomFloat({$scale}, 0, {$max}), {$scale}, '.', '')";
    }

    private static function resolveFloatingPointFaker(FieldDefinition $field): string
    {
        $scale = isset($field->typeArguments[1]) ? (int) $field->typeArguments[1] : 2;

        return "fake()->randomFloat({$scale}, 10, 1000)";
    }
}

<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\FieldDefinition;

final class ValidationRuleResolver
{
    /**
     * Resolve validation rules for a given field definition. Shape only: uniqueness and
     * existence are database questions, answered by the Action that writes, never here.
     *
     * @return array<int, string|RuleExpression>
     */
    public static function resolve(FieldDefinition $field): array
    {
        return array_values(array_filter([
            self::resolvePresenceRule($field),
            ...self::resolveTypeAndFormatRules($field),
        ]));
    }

    private static function resolvePresenceRule(FieldDefinition $field): string
    {
        return $field->isNullable() ? 'nullable' : 'required';
    }

    /**
     * @return array<int, string|RuleExpression>
     */
    private static function resolveTypeAndFormatRules(FieldDefinition $field): array
    {
        return match (strtolower($field->type)) {
            'string', 'char' => self::resolveStringRules($field),
            'text', 'mediumtext', 'longtext' => ['string'],
            'integer', 'tinyinteger', 'smallinteger', 'mediuminteger', 'biginteger',
            'unsignedinteger', 'unsignedtinyinteger', 'unsignedsmallinteger',
            'unsignedmediuminteger', 'unsignedbiginteger', 'int' => ['integer'],
            'boolean', 'bool' => ['boolean'],
            'decimal', 'float', 'double' => ['numeric'],
            'date', 'datetime', 'timestamp' => ['date'],
            'json', 'jsonb' => ['array'],
            'uuid' => ['string', 'uuid'],
            'ulid' => ['string', 'ulid'],
            'enum' => [self::enumRule($field)],
            'foreignid', 'id' => ['integer'],
            'foreignuuid' => ['string', 'uuid'],
            'foreignulid' => ['string', 'ulid'],
            default => ['string'],
        };
    }

    /**
     * @return array<int, string>
     */
    private static function resolveStringRules(FieldDefinition $field): array
    {
        return array_values(array_filter([
            'string',
            self::resolveStringMaxRule($field),
            self::resolveEmailRule($field),
            self::resolvePasswordRule($field),
            self::resolveUrlRule($field),
        ]));
    }

    private static function resolveStringMaxRule(FieldDefinition $field): string
    {
        return isset($field->typeArguments[0])
            ? "max:{$field->typeArguments[0]}"
            : 'max:255';
    }

    private static function resolveEmailRule(FieldDefinition $field): ?string
    {
        return self::isNamed($field, 'email') ? 'email' : null;
    }

    private static function resolvePasswordRule(FieldDefinition $field): ?string
    {
        return self::isNamed($field, 'password') ? 'min:8' : null;
    }

    private static function resolveUrlRule(FieldDefinition $field): ?string
    {
        return self::isNamed($field, 'url') ? 'url' : null;
    }

    /**
     * The field is called exactly this, or its name ends in an underscore and this (billing_email).
     */
    private static function isNamed(FieldDefinition $field, string $name): bool
    {
        return strtolower($field->name) === $name || str_ends_with(strtolower($field->name), "_{$name}");
    }

    private static function enumRule(FieldDefinition $field): RuleExpression
    {
        return new RuleExpression("\\Illuminate\\Validation\\Rule::enum(\\{$field->enumClass()}::class)");
    }
}

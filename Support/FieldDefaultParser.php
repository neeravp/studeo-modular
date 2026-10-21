<?php

namespace Studeo\Modular\Support;

use InvalidArgumentException;
use Studeo\Modular\DataObjects\FieldDefault;

/**
 * Turns the token of a default(...) modifier into one typed default, or fails at parse
 * time. Only integers, booleans, strings, decimals, floats, enums and null on nullable
 * fields have defaults. Dates, json, uuids and keys do not: they are rejected rather than
 * guessed at, because each would need a rule (a clock, an encoding, a generator) that the
 * schema syntax has no way to state.
 */
final class FieldDefaultParser
{
    private const array INTEGER_TYPES = [
        'integer', 'tinyinteger', 'smallinteger', 'mediuminteger', 'biginteger', 'int',
        'unsignedinteger', 'unsignedtinyinteger', 'unsignedsmallinteger', 'unsignedmediuminteger', 'unsignedbiginteger',
    ];

    private const array STRING_TYPES = ['string', 'char', 'text', 'mediumtext', 'longtext'];

    /**
     * @param  array<int, string>  $arguments  the type arguments, such as precision and scale
     */
    public static function parse(string $field, string $type, array $arguments, bool $nullable, string $token): FieldDefault
    {
        $quoted = preg_match('/^([\'"])(.*)\1$/s', $token, $match) === 1;
        $text = $quoted ? stripcslashes($match[2]) : trim($token);

        if (self::isNullLiteral($quoted, $text)) {
            return $nullable ? new FieldDefault(null, 'null') : throw self::reject($field, $type, $text, 'null is only allowed on a nullable field');
        }

        return $text === ''
            ? throw self::reject($field, $type, $text, 'a default cannot be empty')
            : self::typed($field, $type, $arguments, $text);
    }

    /**
     * A decimal default that a structured file wrote as a number: the digits come from the
     * number's shortest string form (never from rounding the float), and the result is padded
     * to exactly the declared scale, so 10.5 with scale 2 is "10.50". More places than the scale
     * is an error; precision is checked when the result is parsed as a default.
     *
     * @param  array<int, string>  $arguments
     */
    public static function decimalFromNumber(string $field, string $type, array $arguments, int|float $number): string
    {
        $text = (string) json_encode($number);
        $scale = (int) ($arguments[1] ?? 2);

        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', $text, $match) !== 1) {
            throw self::reject($field, $type, $text, 'write numbers of this size as a quoted string');
        }

        $places = $match[2] ?? '';

        return strlen($places) > $scale
            ? throw self::reject($field, $type, $text, "more than {$scale} decimal places")
            : $match[1].($scale === 0 ? '' : '.'.str_pad($places, $scale, '0'));
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private static function typed(string $field, string $type, array $arguments, string $text): FieldDefault
    {
        $lower = strtolower($type);
        $fail = fn (string $expected): InvalidArgumentException => self::reject($field, $type, $text, $expected);

        return match (true) {
            in_array($lower, self::INTEGER_TYPES, true) => self::integer($lower, $text, $fail),
            in_array($lower, ['boolean', 'bool'], true) => self::boolean($text, $fail),
            in_array($lower, self::STRING_TYPES, true) => self::string($lower, $arguments, $text, $fail),
            $lower === 'decimal' => self::decimal($arguments, $text, $fail),
            in_array($lower, ['float', 'double'], true) => self::float($text, $fail),
            $lower === 'enum' => self::enum($text, $fail),
            default => throw $fail('defaults are not supported for this type'),
        };
    }

    private static function integer(string $type, string $text, callable $fail): FieldDefault
    {
        if (preg_match('/^-?\d+$/', $text) !== 1) {
            throw $fail('expected an integer, e.g. default(0)');
        }

        return self::isNegativeUnsigned($type, $text)
            ? throw $fail('an unsigned integer cannot be negative')
            : new FieldDefault((int) $text, 'int');
    }

    private static function boolean(string $text, callable $fail): FieldDefault
    {
        return in_array(strtolower($text), ['true', 'false'], true)
            ? new FieldDefault(strtolower($text) === 'true', 'bool')
            : throw $fail('expected true or false');
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private static function string(string $type, array $arguments, string $text, callable $fail): FieldDefault
    {
        $max = in_array($type, ['string', 'char'], true) ? (int) ($arguments[0] ?? 255) : PHP_INT_MAX;

        return mb_strlen($text) > $max ? throw $fail("longer than the declared length of {$max}") : new FieldDefault($text, 'string');
    }

    /**
     * A decimal default is written with exactly the declared scale and must fit the precision.
     *
     * @param  array<int, string>  $arguments
     */
    private static function decimal(array $arguments, string $text, callable $fail): FieldDefault
    {
        $precision = (int) ($arguments[0] ?? 8);
        $scale = (int) ($arguments[1] ?? 2);
        $pattern = $scale === 0 ? '/^-?(\d+)$/' : '/^-?(\d+)\.\d{'.$scale.'}$/';

        if (preg_match($pattern, $text, $match) !== 1) {
            throw $fail("expected a number with exactly {$scale} decimal places, e.g. default(".number_format(0, $scale, '.', '').')');
        }

        return strlen(ltrim($match[1], '0') ?: '0') > $precision - $scale
            ? throw $fail("does not fit decimal({$precision},{$scale})")
            : new FieldDefault($text, 'decimal');
    }

    private static function float(string $text, callable $fail): FieldDefault
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $text) === 1
            ? new FieldDefault($text, 'float')
            : throw $fail('expected a number, e.g. default(1.5)');
    }

    /**
     * The stored string value of the backed enum. The class is not loaded at generation time,
     * so only what any 30 character column value must satisfy is checked.
     */
    private static function enum(string $text, callable $fail): FieldDefault
    {
        return mb_strlen($text) > 30 || preg_match('/[[:cntrl:]]/', $text) === 1
            ? throw $fail('an enum value is a printable string of at most 30 characters')
            : new FieldDefault($text, 'enum');
    }

    private static function reject(string $field, string $type, string $token, string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException("Field [{$field}]: default [{$token}] is not valid for type [{$type}]: {$reason}.");
    }

    private static function isNullLiteral(bool $quoted, string $text): bool
    {
        return ! $quoted && strtolower($text) === 'null';
    }

    private static function isNegativeUnsigned(string $type, string $text): bool
    {
        return str_starts_with($type, 'unsigned') && $text[0] === '-';
    }
}

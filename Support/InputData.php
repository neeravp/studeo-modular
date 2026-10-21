<?php

namespace Studeo\Modular\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ReflectionParameter;
use Studeo\Modular\Support\Attributes\Context;

/**
 * Data entering a boundary. Validate first, then hydrate; there is no public from().
 *
 * Subclasses must declare a protected constructor so the only way in is fromValidated().
 * PHP cannot enforce that here; the DtoShape architecture rule does, and
 * the generator conformance test checks it for generated Inputs.
 */
abstract readonly class InputData extends DataObject
{
    /**
     * @return array<string, mixed>
     */
    abstract public static function rules(): array;

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [];
    }

    /**
     * Validate the payload, then hydrate. Only keys covered by rules() are read;
     * #[Context] properties come only from $context (snake_case keys).
     *
     * @param  array<string, mixed>|Request  $source
     * @param  array<string, mixed>  $context
     *
     * @throws ValidationException
     */
    public static function fromValidated(array|Request $source, array $context = []): static
    {
        $payload = Arr::except(self::validate($source), self::contextKeys());

        return static::hydrate([...$payload, ...self::ensureKnownContext($context)]);
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, array $context = []): static
    {
        return static::fromValidated($request, $context);
    }

    /**
     * Prefix another Input's rules, e.g. nestedRules('items.*', ItemInput::class).
     *
     * @param  class-string<InputData>  $input
     * @return array<string, mixed>
     */
    protected static function nestedRules(string $prefix, string $input): array
    {
        $rules = [];

        foreach ($input::rules() as $key => $rule) {
            $rules["{$prefix}.{$key}"] = $rule;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>|Request  $source
     * @return array<string, mixed>
     */
    private static function validate(array|Request $source): array
    {
        $payload = $source instanceof Request ? $source->all() : $source;

        return Validator::make($payload, static::rules(), static::messages(), static::attributes())->validate();
    }

    /**
     * @return array<int, string>
     */
    private static function contextKeys(): array
    {
        $contextual = array_filter(static::constructorParameters(), static::isContext(...));

        return array_values(array_map(fn (ReflectionParameter $parameter): string => str($parameter->getName())->snake()->toString(), $contextual));
    }

    private static function isContext(ReflectionParameter $parameter): bool
    {
        return $parameter->getAttributes(Context::class) !== [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function ensureKnownContext(array $context): array
    {
        $unknown = array_diff(array_keys($context), self::contextKeys());

        return $unknown === []
            ? $context
            : throw new InvalidArgumentException('Unknown context ['.implode(', ', $unknown).'] for ['.static::class.'].');
    }
}

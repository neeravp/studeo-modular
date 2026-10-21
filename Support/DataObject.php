<?php

// strict_types is intentionally absent from this file and from generated data
// classes: hydration relies on PHP's non-strict coercion of request strings
// ("5" becomes an int, "0" becomes false) when calling the constructor.

namespace Studeo\Modular\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use Studeo\Modular\Support\Attributes\Collection as CollectionOf;
use UnitEnum;

/**
 * Shared hydration and serialization for immutable data objects.
 *
 * Wire keys are snake_case; PHP properties are camelCase.
 *
 * @implements Arrayable<string, mixed>
 */
abstract readonly class DataObject implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * Convert the data object to a snake_case array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->publicProperties() as $property) {
            $result[Str::snake($property->getName())] = $this->transformValueToArray($property->getValue($this));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Build an instance from snake_case wire data.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function hydrate(array $data): static
    {
        $arguments = [];

        foreach (static::constructorParameters() as $parameter) {
            $arguments[$parameter->getName()] = static::resolveArgument($parameter, $data);
        }

        return new static(...$arguments);
    }

    /**
     * @return array<int, ReflectionParameter>
     */
    protected static function constructorParameters(): array
    {
        return (new ReflectionClass(static::class))->getConstructor()?->getParameters() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function resolveArgument(ReflectionParameter $parameter, array $data): mixed
    {
        $key = Str::snake($parameter->getName());

        return match (true) {
            array_key_exists($key, $data) => static::castArgument($parameter, $data[$key]),
            $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
            $parameter->allowsNull() => null,
            default => throw new InvalidArgumentException(
                "Missing required property [{$parameter->getName()}] for [".static::class.'].'
            ),
        };
    }

    protected static function castArgument(ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();

        return match (true) {
            $value === null => null,
            self::isCollectionValue($parameter, $value) => static::castCollection($parameter, $value),
            self::isBuiltinOrUntyped($type) => $value,
            default => static::castToClass($type->getName(), $value),
        };
    }

    /**
     * @return class-string|null
     */
    protected static function collectionClass(ReflectionParameter $parameter): ?string
    {
        $attribute = $parameter->getAttributes(CollectionOf::class)[0] ?? null;

        return $attribute?->newInstance()->of;
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array<array-key, mixed>
     */
    protected static function castCollection(ReflectionParameter $parameter, array $items): array
    {
        $className = static::collectionClass($parameter);

        return array_map(fn (mixed $item): mixed => static::castToClass($className, $item), $items);
    }

    /**
     * Cast a value to its declared class: data object, backed enum, or date.
     * Enums use from(), so an invalid value fails loudly.
     */
    protected static function castToClass(string $className, mixed $value): mixed
    {
        return match (true) {
            $value instanceof $className => $value,
            is_subclass_of($className, OutputData::class) => $className::from($value),
            is_subclass_of($className, self::class) => $className::hydrate($value),
            is_subclass_of($className, BackedEnum::class) => $className::from($value),
            is_a($className, DateTimeInterface::class, true) => static::castToDate($className, $value),
            default => $value,
        };
    }

    /**
     * Hydrate a date by its declared class. A bare interface becomes CarbonImmutable.
     *
     * A property declared as mutable Carbon hydrates as mutable Carbon, which breaks
     * deep immutability. Generators (G4) must emit CarbonImmutable only.
     */
    protected static function castToDate(string $className, mixed $value): DateTimeInterface
    {
        $concrete = interface_exists($className) ? CarbonImmutable::class : $className;

        return match (true) {
            is_a($concrete, CarbonInterface::class, true) => $concrete::parse($value),
            $value instanceof DateTimeInterface => $concrete::createFromInterface($value),
            default => new $concrete($value),
        };
    }

    /**
     * @return array<int, ReflectionProperty>
     */
    private function publicProperties(): array
    {
        $properties = (new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC);

        return array_filter($properties, fn (ReflectionProperty $property): bool => ! $property->isStatic());
    }

    /**
     * Dates come before JsonSerializable: Carbon implements both and must be formatted as ATOM.
     */
    private function transformValueToArray(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Arrayable => $value->toArray(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof UnitEnum => $value instanceof BackedEnum ? $value->value : $value->name,
            is_array($value) => array_map(fn (mixed $item): mixed => $this->transformValueToArray($item), $value),
            default => $value,
        };
    }

    private static function isCollectionValue(ReflectionParameter $parameter, mixed $value): bool
    {
        return is_array($value) && static::collectionClass($parameter) !== null;
    }

    private static function isBuiltinOrUntyped(?ReflectionType $type): bool
    {
        return ! $type instanceof ReflectionNamedType || $type->isBuiltin();
    }
}

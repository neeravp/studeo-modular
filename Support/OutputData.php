<?php

namespace Studeo\Modular\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Data leaving a boundary: built from models, arrays or objects. Carries no rules.
 */
abstract readonly class OutputData extends DataObject
{
    public static function from(mixed $source): static
    {
        return $source instanceof static ? $source : static::hydrate(self::extractData($source));
    }

    /**
     * @param  iterable<mixed>  $items
     * @return Collection<int, static>
     */
    public static function collect(iterable $items): Collection
    {
        return collect($items)->map(fn (mixed $item): static => static::from($item))->values();
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractData(mixed $source): array
    {
        return match (true) {
            is_array($source) => $source,
            $source instanceof Model => array_merge($source->attributesToArray(), $source->relationsToArray()),
            $source instanceof Arrayable => $source->toArray(),
            is_object($source) => self::snakeKeys(get_object_vars($source)),
            default => throw new InvalidArgumentException(
                'Cannot instantiate ['.static::class.'] from source of type ['.get_debug_type($source).'].'
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function snakeKeys(array $data): array
    {
        return collect($data)->mapWithKeys(fn (mixed $value, string $key): array => [Str::snake($key) => $value])->all();
    }
}

<?php

namespace Studeo\Modular\DataObjects;

/**
 * What a factory's definition() holds for a schema: the faker expression of every key it fakes, and,
 * for every foreign key it leaves out, the reason, keyed by column.
 */
final readonly class FactoryDefinition
{
    /**
     * @param  array<string, string>  $attributes
     * @param  array<string, string>  $omitted
     */
    public function __construct(
        public array $attributes,
        public array $omitted = [],
    ) {}
}

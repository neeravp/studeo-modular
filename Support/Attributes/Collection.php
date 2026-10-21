<?php

namespace Studeo\Modular\Support\Attributes;

use Attribute;

/**
 * Declares the data class of every item in an array property.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Collection
{
    /**
     * @param  class-string  $of
     */
    public function __construct(public string $of) {}
}

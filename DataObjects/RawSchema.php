<?php

namespace Studeo\Modular\DataObjects;

/**
 * A schema as read from the CLI form, YAML or JSON, before any rule has been applied. Fields
 * and relationships are lists, so a name written twice is still there to be reported.
 */
final readonly class RawSchema
{
    /**
     * @param  array<int, RawField>  $fields
     * @param  array<int, RawRelationship>  $relationships
     * @param  array<int, string>  $unknownKeys  top-level keys the structured form does not know
     * @param  array<int, string>  $malformed  sections or keys that are the wrong shape
     */
    public function __construct(
        public mixed $name = null,
        public mixed $table = null,
        public array $fields = [],
        public array $relationships = [],
        public array $unknownKeys = [],
        public array $malformed = [],
    ) {}
}

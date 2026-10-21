<?php

namespace Studeo\Modular\DataObjects;

/**
 * A relationship exactly as the author wrote it.
 */
final readonly class RawRelationship
{
    /**
     * @param  array<int, string>  $unknownKeys
     * @param  array<int, string>  $malformed
     */
    public function __construct(
        public string $name,
        public string $type = 'belongsTo',
        public ?string $relatedModel = null,
        public ?string $foreignKey = null,
        public ?string $ownerKey = null,
        public array $unknownKeys = [],
        public array $malformed = [],
    ) {}
}

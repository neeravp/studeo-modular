<?php

namespace Studeo\Modular\DataObjects;

/**
 * What an Action controller needs, decided before anything is written: the Action and its Input
 * (shared with the forms), the schema that generates them when missing, and the URL its printed
 * route serves.
 */
final readonly class ControllerActionPlan
{
    public function __construct(
        public FormActionPlan $action,
        public ?SchemaDefinition $schema = null,
        public ?string $url = null,
    ) {}
}

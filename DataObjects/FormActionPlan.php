<?php

namespace Studeo\Modular\DataObjects;

/**
 * What any generated form needs from the Action it saves through, decided before anything is
 * written: the Action and its paired Input (and whether each exists), the fields the form
 * carries and the Input's context keys, which the server supplies.
 */
final readonly class FormActionPlan
{
    /**
     * @param  array<int, FieldDefinition>  $fields  the Input's fields minus the context ones
     * @param  array<int, string>  $context  snake_case context keys
     * @param  array<int, string>  $notices
     */
    public function __construct(
        public string $actionName,
        public string $inputName,
        public string $actionClass,
        public string $inputClass,
        public bool $actionExists,
        public bool $inputExists,
        public array $fields,
        public array $context,
        public array $notices = [],
    ) {}
}

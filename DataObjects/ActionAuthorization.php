<?php

namespace Studeo\Modular\DataObjects;

/**
 * How a generated Action authorizes: the model class its Gate call names ('' when it names none), the call itself (the model written as `{{ model }}`), and, when the
 * call cannot be settled from the module's models, the notice that says what it does until it is.
 */
final readonly class ActionAuthorization
{
    public function __construct(
        public string $modelClass,
        public string $call,
        public ?SchemaIssue $notice = null,
    ) {}
}

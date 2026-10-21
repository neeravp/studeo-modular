<?php

namespace Studeo\Modular\Support\Attributes;

use Attribute;

/**
 * Marks an InputData property that never comes from the request payload.
 * Its value is supplied only through the context passed to fromValidated().
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Context {}

<?php

namespace Studeo\Modular\Support\Attributes;

use Attribute;

/**
 * Marks an Action that does no authorization of its own. It is callable only from
 * other Actions, Listeners and Jobs, never from controllers, middleware, API resources
 * or Livewire components.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SystemAction {}

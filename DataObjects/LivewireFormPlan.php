<?php

namespace Studeo\Modular\DataObjects;

/**
 * What a Livewire form needs, decided before anything is written: the Action plan it shares with
 * every form generator, the Form class and the #[Context] keys the actor supplies.
 */
final readonly class LivewireFormPlan
{
    public function __construct(
        public FormActionPlan $action,
        public string $formClass,
        public string $formPath,
    ) {}
}

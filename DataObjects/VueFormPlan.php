<?php

namespace Studeo\Modular\DataObjects;

/**
 * What a Vue form page needs, decided before anything is written: the Action plan it shares with
 * every form generator and the URL its submit posts to.
 */
final readonly class VueFormPlan
{
    public function __construct(
        public FormActionPlan $action,
        public string $url,
    ) {}
}

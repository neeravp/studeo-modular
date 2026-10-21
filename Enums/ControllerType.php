<?php

namespace Studeo\Modular\Enums;

enum ControllerType: string
{
    case Invokable = 'invokable';
    case Resource = 'resource';
    case Api = 'api';
    case Standard = 'standard';

    public function bindsModel(): bool
    {
        return $this === self::Resource || $this === self::Api;
    }
}

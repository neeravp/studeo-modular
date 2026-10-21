<?php

namespace Studeo\Modular\Enums;

enum MigrationType: string
{
    case Create = 'create';
    case Update = 'update';
    case Blank = 'blank';
    case Pivot = 'pivot';
}

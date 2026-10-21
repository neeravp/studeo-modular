<?php

namespace Studeo\Modular\Enums;

enum FrontendFileState: string
{
    case Configured = 'configured';
    case Scaffoldable = 'scaffoldable';
    case Custom = 'custom';
}

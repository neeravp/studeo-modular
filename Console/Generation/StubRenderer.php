<?php

namespace Studeo\Modular\Console\Generation;

final class StubRenderer
{
    /**
     * Resolve the absolute path to a console stub template file.
     */
    public function path(string $name): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.$name;
    }
}

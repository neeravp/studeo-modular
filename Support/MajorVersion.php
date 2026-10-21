<?php

namespace Studeo\Modular\Support;

final class MajorVersion
{
    /**
     * The major of an installed version or a declared range ("v3.0.2", "^3.0", "~2.1.0"), or
     * null when it names none ("latest", "*", "dev-main").
     */
    public static function of(?string $version): ?int
    {
        return preg_match('/^[\^~>=<v\s]*(\d+)/', (string) $version, $matches) === 1 ? (int) $matches[1] : null;
    }
}

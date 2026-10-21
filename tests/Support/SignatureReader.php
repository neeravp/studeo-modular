<?php

namespace Studeo\Modular\Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Reads the `$signature` string out of a generated command class.
 */
final class SignatureReader
{
    public static function of(string $path): string
    {
        preg_match('/\$signature = \'([^\']*)\';/', File::get($path), $match);

        return $match[1];
    }
}

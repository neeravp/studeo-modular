<?php

namespace Studeo\Modular\Support;

use RuntimeException;

/**
 * The URL a generated form posts to or a printed route serves: an absolute path of segments made of
 * letters, digits and - _ . ~, none starting with a dot. Nothing it accepts needs escaping in PHP or
 * in a template string.
 */
final class RoutePath
{
    private const string PATTERN = '#^/(?:[A-Za-z0-9_~-][A-Za-z0-9._~-]*(?:/[A-Za-z0-9_~-][A-Za-z0-9._~-]*)*)?$#';

    /**
     * @throws RuntimeException
     */
    public static function check(string $url, string $example): string
    {
        if (preg_match(self::PATTERN, $url) !== 1) {
            throw new RuntimeException("The URL [{$url}] is not valid: use a path that starts with /, made of letters, digits, - _ . ~ and /, e.g. {$example}.");
        }

        return $url;
    }
}

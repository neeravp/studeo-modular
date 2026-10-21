<?php

namespace Studeo\Modular\Console\Generation;

use Illuminate\Support\Str;
use RuntimeException;

final class NameSanitizer
{
    /**
     * Sanitize a single flat PHP class name, optionally ensuring a required suffix. The name
     * must be one PHP can load, so it cannot start with a digit.
     */
    public function className(string $name, string $type, ?string $suffix = null): string
    {
        $studly = $this->studlyName($name, $type);

        $this->ensureStartsWithLetter($studly, $name, $type);

        return $this->lacksSuffix($studly, $suffix) ? $studly.$suffix : $studly;
    }

    /**
     * A single flat name in studly case, for what is not a class (a schema's model name).
     */
    public function studlyName(string $name, string $type): string
    {
        $studly = Str::studly(trim(str_replace(['/', '\\'], '', $name)));

        if ($studly === '') {
            throw new RuntimeException("Invalid {$type} name [{$name}].");
        }

        return $studly;
    }

    /**
     * The one rule for a name that becomes a class: PHP cannot load a class whose name starts
     * with a digit. The message names the name as it was given.
     */
    public function ensureStartsWithLetter(string $class, string $name, string $type): void
    {
        if (preg_match('/^\d/', $class) === 1) {
            throw new RuntimeException("Invalid {$type} name [{$name}].");
        }
    }

    /**
     * Sanitize a hierarchical class path supporting subdirectories (e.g. "Posts/CreatePostAction").
     */
    public function classPath(string $name, string $type): string
    {
        $normalized = str_replace('\\', '/', trim($name));
        $segments = array_filter(explode('/', $normalized), fn (string $s): bool => trim($s) !== '');

        if (count($segments) === 0) {
            throw new RuntimeException("Invalid {$type} name [{$name}].");
        }

        $studlySegments = array_map(fn (string $s): string => Str::studly(trim($s)), $segments);

        return implode('/', $studlySegments);
    }

    private function lacksSuffix(string $studly, ?string $suffix): bool
    {
        return $suffix !== null && ! Str::endsWith($studly, $suffix);
    }
}

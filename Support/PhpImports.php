<?php

namespace Studeo\Modular\Support;

/**
 * The classes a generated file names, collected while its content is built: each class is written
 * by its short name and imported, as Pint's Laravel preset would. A class of the file's own
 * namespace needs no import; a short name already taken by another class, or by the file's own
 * class, stays fully qualified.
 */
final class PhpImports
{
    /** @var array<string, string> short name => class */
    private array $imports;

    public function __construct(private readonly string $namespace, ?string $class = null)
    {
        $this->imports = $class === null ? [] : [$class => "{$namespace}\\{$class}"];
    }

    /**
     * The name to write for a class, given with or without a leading backslash.
     */
    public function name(string $class): string
    {
        $class = ltrim($class, '\\');
        $short = class_basename($class);
        $taken = $this->imports[$short] ?? $class;

        if ($taken !== $class) {
            return '\\'.$class;
        }

        $this->imports[$short] = $class;

        return $short;
    }

    /**
     * Registers classes as imports, in the order given, for a file whose body writes them itself.
     */
    public function add(string ...$classes): void
    {
        foreach ($classes as $class) {
            $this->name($class);
        }
    }

    /**
     * A type as written in a signature or docblock: a class (with an optional leading `?`) is
     * named through name(); a builtin type is returned as it is.
     */
    public function type(string $type): string
    {
        $class = ltrim($type, '?');

        return str_starts_with($class, '\\') ? substr($type, 0, strlen($type) - strlen($class)).$this->name($class) : $type;
    }

    /**
     * The use statements, sorted as Pint sorts them, one per line; '' when there are none.
     */
    public function statements(): string
    {
        $classes = array_values(array_filter($this->imports, fn (string $class): bool => ! $this->isOwnNamespace($class)));
        usort($classes, fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

        return implode("\n", array_map(fn (string $class): string => "use {$class};", $classes));
    }

    private function isOwnNamespace(string $class): bool
    {
        return strcasecmp(substr($class, 0, (int) strrpos($class, '\\')), $this->namespace) === 0;
    }
}

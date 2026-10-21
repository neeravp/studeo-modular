<?php

namespace Studeo\Modular\Support;

use InvalidArgumentException;

/**
 * The one place a module's namespace is built and checked: the name of a module, the root
 * namespace in front of it, and the namespace a manifest declares.
 */
final class ModuleNamespace
{
    /**
     * First segments the root application or a common vendor owns.
     */
    public const array RESERVED = [
        'App', 'Database', 'Tests', 'Illuminate', 'Livewire', 'Inertia',
        'Laravel', 'Symfony', 'Composer', 'PHPUnit', 'Pest',
    ];

    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const string NAME = '/^[A-Z][A-Za-z0-9]*$/';

    /**
     * The full namespace of a module: the prefix (none when null or empty) and the module name.
     *
     * @throws InvalidArgumentException naming the segment and the reason
     */
    public static function for(string $name, ?string $prefix): string
    {
        self::assertName($name);

        if (self::hasNoPrefix($prefix)) {
            self::assertValid($name);

            return $name;
        }

        self::assertValid($prefix, 'root namespace');
        self::assertValid($prefix.'\\'.$name);

        return $prefix.'\\'.$name;
    }

    /**
     * @throws InvalidArgumentException when the name is not one studly segment
     */
    public static function assertName(string $name): void
    {
        $problem = match (true) {
            trim($name) === '' => 'The module name can not be blank',
            preg_match('/[\\\\\/]/', $name) === 1 => self::nestedName($name),
            preg_match(self::NAME, $name) !== 1 => "The module name [{$name}] must be one StudlyCase segment of letters and digits, for example Account or BlogPosts.",
            default => null,
        };

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
    }

    /**
     * @throws InvalidArgumentException when the namespace is not valid segments, or starts with a reserved one
     */
    public static function assertValid(string $namespace, string $what = 'namespace'): void
    {
        self::assertShape($namespace, $what);

        $invalid = array_filter(explode('\\', $namespace), fn (string $segment): bool => preg_match(self::IDENTIFIER, $segment) !== 1);
        $segment = array_shift($invalid);

        if ($segment !== null) {
            throw new InvalidArgumentException("[{$segment}] is not a valid PHP identifier, in the {$what} [{$namespace}].");
        }

        self::assertNotReserved($namespace, $what);
    }

    private static function assertShape(string $namespace, string $what): void
    {
        $problem = match (true) {
            str_starts_with($namespace, '\\') => 'must not start with a backslash',
            str_ends_with($namespace, '\\') => 'must not end with a backslash',
            str_contains($namespace, '\\\\') => 'has an empty segment',
            default => null,
        };

        if ($problem !== null) {
            throw new InvalidArgumentException("The {$what} [{$namespace}] {$problem}.");
        }
    }

    private static function assertNotReserved(string $namespace, string $what = 'namespace'): void
    {
        $first = explode('\\', $namespace)[0];

        if (array_filter(self::RESERVED, fn (string $reserved): bool => strcasecmp($first, $reserved) === 0) !== []) {
            throw new InvalidArgumentException("The {$what} [{$namespace}] starts with [{$first}], which belongs to the root application or a common vendor. Choose another module name, or a root namespace such as --root-namespace=Acme.");
        }
    }

    private static function nestedName(string $name): string
    {
        $parts = explode('\\', str_replace('/', '\\', $name));

        if (self::isPrefixAndName($parts)) {
            return "The module name [{$name}] must be one segment. To prefix a module, use --root-namespace={$parts[0]} with the name {$parts[1]}.";
        }

        return "The module name [{$name}] must be one segment, for example Account. To prefix a module, use --root-namespace.";
    }

    /**
     * @phpstan-assert-if-false string $prefix
     */
    private static function hasNoPrefix(?string $prefix): bool
    {
        return $prefix === null || $prefix === '';
    }

    /**
     * @param  array<int, string>  $parts
     */
    private static function isPrefixAndName(array $parts): bool
    {
        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '';
    }
}

<?php

/**
 * Loads every PHP class of a generated module in this fresh process, as the application would:
 * a class is autoloaded through the module's manifest namespace, a migration or a Livewire
 * multi-file component, which is an anonymous class, is required. Blade templates, routes, config
 * and the Pest tests are not classes and are left alone.
 *
 * Usage: php load-module.php <autoload.php> <module path>
 * Prints {"loaded": [...], "errors": [...]} and exits 1 when there is an error; a fatal error
 * (a readonly class under a non-readonly parent, say) ends the process with its message on stderr.
 */
[, $autoload, $module] = $argv;

require $autoload;

$namespace = json_decode(file_get_contents("{$module}/module.json"), true, flags: JSON_THROW_ON_ERROR)['namespace'];

spl_autoload_register(fn (string $class) => requireModuleClass($module, $namespace, $class));

$loaded = [];
$errors = [];

foreach (moduleFiles($module) as $file) {
    [$names, $problems] = loadFile($module, $file, $namespace);
    $loaded = [...$loaded, ...$names];
    $errors = [...$errors, ...$problems];
}

echo json_encode(['loaded' => $loaded, 'errors' => $errors]);

exit($errors === [] ? 0 : 1);

function requireModuleClass(string $module, string $namespace, string $class): void
{
    $file = (string) moduleClassFile($module, $namespace, $class);

    if (is_file($file)) {
        require_once $file;
    }
}

/** The PSR-4 file of a class: the factories and seeders have their own prefixes. */
function moduleClassFile(string $module, string $namespace, string $class): ?string
{
    $roots = ["{$namespace}\\Database\\Factories\\" => 'database/factories/', "{$namespace}\\Database\\Seeders\\" => 'database/seeders/', "{$namespace}\\" => ''];

    foreach ($roots as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            return $module.'/'.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    }

    return null;
}

/** @return list<string> module-relative paths of the PHP files, Blade templates left out */
function moduleFiles(string $module): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = substr($file->getPathname(), strlen($module) + 1);
    }

    sort($files);

    return array_values(array_filter($files, fn (string $path): bool => str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')));
}

/** @return array{0: list<string>, 1: list<string>} what was loaded and what went wrong */
function loadFile(string $module, string $file, string $namespace): array
{
    $types = declaredTypes("{$module}/{$file}");

    return match (true) {
        isNotCode($file) => [[], []],
        $types === [] => requireAnonymous($module, $file),
        default => autoloadTypes($file, $types),
    };
}

/** Routes, config and Pest tests (also the `.test.php` beside a multi-file component) are files, not classes. */
function isNotCode(string $file): bool
{
    return preg_match('~^(routes|config|tests)/|\.test\.php$~', $file) === 1;
}

/** @return array{0: list<string>, 1: list<string>} */
function requireAnonymous(string $module, string $file): array
{
    $before = count(get_declared_classes());

    require "{$module}/{$file}";

    return [[$file], count(get_declared_classes()) > $before ? [] : ["{$file}: declares no class"]];
}

/**
 * @param  list<string>  $types
 * @return array{0: list<string>, 1: list<string>}
 */
function autoloadTypes(string $file, array $types): array
{
    $missing = array_values(array_filter($types, fn (string $type): bool => ! (class_exists($type) || interface_exists($type) || trait_exists($type) || enum_exists($type))));

    return [array_values(array_diff($types, $missing)), array_map(fn (string $type): string => "{$file}: {$type} is not loadable", $missing)];
}

/** @return list<string> the fully qualified classes, interfaces, traits and enums a file declares */
function declaredTypes(string $path): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize(file_get_contents($path)), fn (PhpToken $token): bool => ! $token->isIgnorable()));
    $namespace = declaredNamespace($tokens);
    $declarations = array_filter(array_keys($tokens), fn (int $index): bool => isDeclaration($tokens, $index));

    return array_values(array_map(fn (int $index): string => ltrim($namespace.'\\'.$tokens[$index + 1]->text, '\\'), $declarations));
}

/** @param list<PhpToken> $tokens */
function declaredNamespace(array $tokens): string
{
    foreach ($tokens as $index => $token) {
        if ($token->is(T_NAMESPACE)) {
            return $tokens[$index + 1]->text;
        }
    }

    return '';
}

/** @param list<PhpToken> $tokens */
function isDeclaration(array $tokens, int $index): bool
{
    $declares = $tokens[$index]->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]);
    $named = isset($tokens[$index + 1]) && $tokens[$index + 1]->is(T_STRING);
    $constant = $index > 0 && $tokens[$index - 1]->is(T_DOUBLE_COLON);

    return $declares && $named && ! $constant;
}

<?php

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use Studeo\Modular\Tests\Support\PackageSyntaxTrees;
use Symfony\Component\Process\Process;

it('declares and uses only the two thin command traits', function () {
    $allowed = ['Console/Concerns/ChecksPrompting.php', 'Console/Concerns/ReportsFailures.php'];
    sort($allowed);
    $declaring = array_keys(array_filter(
        PackageSyntaxTrees::all(),
        fn (array $tree): bool => (new NodeFinder)->findFirstInstanceOf($tree, Trait_::class) !== null,
    ));
    $used = [];
    foreach (PackageSyntaxTrees::all() as $tree) {
        $uses = (new NodeFinder)->findInstanceOf($tree, TraitUse::class);
        $used = [...$used, ...array_merge([], ...array_map(fn (TraitUse $use): array => array_map(fn ($name): string => $name->getLast(), $use->traits), $uses))];
    }

    sort($declaring);

    expect($declaring)->toBe($allowed)
        ->and(array_diff($used, ['ChecksPrompting', 'ReportsFailures']))->toBe([]);
});

it('keeps each command trait short and free of state', function () {
    $offenders = [];
    foreach (['Console/Concerns/ChecksPrompting.php', 'Console/Concerns/ReportsFailures.php'] as $path) {
        $lines = count(file(dirname(__DIR__, 3)."/{$path}"));
        $properties = (new NodeFinder)->findInstanceOf(PackageSyntaxTrees::all()[$path], Node\Stmt\Property::class);
        $offenders[$path] = $lines > 60 || $properties !== [] ? "{$lines} lines, ".count($properties).' properties' : null;
    }

    expect(array_filter($offenders))->toBe([]);
});

it('keeps classes that offer static helpers abstract, or final and immutable or stateless', function () {
    $offenders = [];

    foreach (PackageSyntaxTrees::all() as $path => $tree) {
        $reachable = array_filter(PackageSyntaxTrees::staticHelperMethods($tree), fn (ClassMethod $method): bool => ! $method->isPrivate());
        $named = array_filter((new NodeFinder)->findInstanceOf($tree, Class_::class), fn (Class_ $class): bool => $class->name !== null);
        $classes = $reachable === [] ? [] : $named;
        $offenders = [...$offenders, ...array_map(fn (Class_ $class): string => "{$path} may not offer static helpers", array_filter($classes, fn (Class_ $class): bool => ! PackageSyntaxTrees::mayOfferStaticHelpers($class)))];
    }

    expect($offenders)->toBe([]);
});

it('does no input or output in static helpers, apart from the listed ones that only shrink', function () {
    $known = [
        'Support/Schema/ModuleModels.php::hasFactory calls is_file',
        'Support/Schema/ModuleModels.php::names calls glob',
        'Support/Schema/ModuleModels.php::tables calls file_get_contents',
        'Support/Schema/SchemaFiles.php::tables calls glob',
    ];
    $found = [];

    foreach (PackageSyntaxTrees::all() as $path => $tree) {
        foreach (PackageSyntaxTrees::staticHelperMethods($tree) as $method) {
            $calls = (new NodeFinder)->findInstanceOf($method, FuncCall::class);
            $io = array_filter($calls, fn (FuncCall $call): bool => $call->name instanceof Node\Name && in_array($call->name->toString(), PackageSyntaxTrees::IO_FUNCTIONS, true));
            $found = [...$found, ...array_map(fn (FuncCall $call): string => "{$path}::{$method->name} calls {$call->name}", $io)];
        }
    }
    sort($found);

    expect(array_values(array_diff($found, $known)))->toBe([])
        ->and(array_values(array_diff($known, $found)))->toBe([]);
});

it('keeps the data, exception and rule layers free of console imports', function () {
    $offenders = [];

    foreach (PackageSyntaxTrees::all() as $path => $tree) {
        $isLowerLayer = in_array(explode('/', $path)[0], ['Check', 'DataObjects', 'Exceptions', 'Support'], true);
        $bad = $isLowerLayer ? array_filter(PackageSyntaxTrees::imports($tree), fn (string $import): bool => str_starts_with($import, 'Studeo\\Modular\\Console')) : [];
        $offenders = [...$offenders, ...array_map(fn (string $import): string => "{$path} imports {$import}", $bad)];
    }

    expect($offenders)->toBe([]);
});

it('keeps local paths and internal tool names out of tracked files', function () {
    $root = dirname(__DIR__, 3);
    $listing = new Process(['git', 'ls-files'], $root);
    $listing->mustRun();
    $words = ['.gemini', 'dev/agent', '.ai/', 'charter', 'reviewer', 'claude', 'agent'];
    $paths = ['#/Users/[a-z0-9._-]+/#', '#/home/[a-z0-9._-]+/#'];
    $found = [];

    foreach (array_filter(explode("\n", $listing->getOutput())) as $file) {
        $isScanned = is_file("{$root}/{$file}") && ! in_array($file, ['composer.lock', 'package-lock.json', 'tests/Unit/Architecture/PackageInvariantsTest.php'], true);
        $contents = $isScanned ? (string) file_get_contents("{$root}/{$file}") : '';
        $wordHits = array_filter($words, fn (string $word): bool => stripos($contents, $word) !== false);
        $pathHits = array_filter($paths, fn (string $pattern): bool => preg_match($pattern, $contents) === 1);
        $found[$file] = [...array_values($wordHits), ...array_values($pathHits)];
    }

    expect(array_filter($found))->toBe([]);
});

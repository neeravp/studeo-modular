<?php

use Studeo\Modular\Check\Rules\FlatModules;

beforeEach(function () {
    $this->root = [
        'module.json' => '{}',
        'BlogServiceProvider.php' => "<?php\n",
        'Actions/Run.php' => phpFile('final class Run {}'),
    ];
    $this->everything = [
        'BlogServiceProvider.php' => "<?php\n",
        'stray.php' => "<?php\n",
        'Http/readme.txt' => 'x',
        'Services/Inner/module.json' => '{}',
        'Actions/Bad.php' => phpFile('final class Bad {}', 'Elsewhere'),
        'Services/Bad.php' => phpFile('final class Bad {}', 'Elsewhere'),
    ];
});

it('reports a missing module.json only when the scope contains it', function (array $scope, array $expected) {
    $files = $this->root;
    unset($files['module.json']);

    expect(summarise(runRule(FlatModules::options(scope: $scope), $files)))->toBe($expected);
})->with([
    'whole module' => [['.'], ['modules/Blog/module.json:1 module.json']],
    'a scope that names the manifest' => [['module.json'], ['modules/Blog/module.json:1 module.json']],
    'a class directory' => [['Actions'], []],
]);

it('lists stray root PHP files in byte order, counts blade files and ignores dot-files and the provider', function () {
    $files = [...$this->root, 'zeta.php' => "<?php\n", 'alpha.php' => "<?php\n", 'Beta.php' => "<?php\n", 'view.blade.php' => 'x', '.hidden.php' => "<?php\n", 'notes.txt' => 'x'];

    expect(summarise(runRule(FlatModules::options(), $files)))->toBe([
        'modules/Blog/Beta.php:1 Beta.php',
        'modules/Blog/alpha.php:1 alpha.php',
        'modules/Blog/view.blade.php:1 view.blade.php',
        'modules/Blog/zeta.php:1 zeta.php',
    ]);
});

it('drops stray root files outside the scope and keeps one whose own name is the scope', function (array $scope, array $expected) {
    $files = [...$this->root, 'zeta.php' => "<?php\n", 'alpha.php' => "<?php\n"];

    expect(summarise(runRule(FlatModules::options(scope: $scope), $files)))->toBe($expected);
})->with([
    'a class directory' => [['Actions'], []],
    'a scope naming one file' => [['zeta.php'], ['modules/Blog/zeta.php:1 zeta.php']],
]);

it('lists unknown root directories in byte order and ignores dot-directories, vendor and node_modules', function () {
    $files = [...$this->root, 'Zdir/a.txt' => 'x', 'Adir/a.txt' => 'x', 'bdir/a.txt' => 'x', 'vendor/a.txt' => 'x', 'node_modules/a.txt' => 'x', '.git/a.txt' => 'x', '.cache/a.txt' => 'x'];

    expect(summarise(runRule(FlatModules::options(), $files)))->toBe([
        'modules/Blog/Adir:1 Adir',
        'modules/Blog/Zdir:1 Zdir',
        'modules/Blog/bdir:1 bdir',
    ]);
});

it('drops unknown root directories outside the scope', function (array $scope, array $expected) {
    $files = [...$this->root, 'Zdir/a.txt' => 'x', 'Adir/a.txt' => 'x'];

    expect(summarise(runRule(FlatModules::options(scope: $scope), $files)))->toBe($expected);
})->with([
    'a class directory' => [['Actions'], []],
    'a scope naming one directory' => [['Zdir'], ['modules/Blog/Zdir:1 Zdir']],
]);

it('does not look for module.json or modules below a vendor or node_modules directory', function () {
    $files = [...$this->root, 'Services/vendor/module.json' => '{}', 'node_modules/x/module.json' => '{}', 'Services/node_modules/modules/a.txt' => 'x'];

    expect(runRule(FlatModules::options(), $files))->toBe([]);
});

it('reports only the class violation when one class has both a wrong name and a wrong namespace', function () {
    $found = runRule(FlatModules::options(), [...$this->root, 'Services/Both.php' => phpFile('final class Wrong {}', 'Elsewhere')]);

    expect(summarise($found))->toBe(['modules/Blog/Services/Both.php:6 Elsewhere\\Wrong'])
        ->and($found[0]->message)->toContain('class Wrong is in Both.php');
});

it('checks every named class of a file in document order, each on its own', function () {
    $body = "final class Multi {}\nfinal class Other {}\ninterface Third {}";

    expect(summarise(runRule(FlatModules::options(), [...$this->root, 'Services/Multi.php' => phpFile($body, 'Studeo\\Blog\\Services')])))->toBe([
        'modules/Blog/Services/Multi.php:7 Studeo\\Blog\\Services\\Other',
        'modules/Blog/Services/Multi.php:8 Studeo\\Blog\\Services\\Third',
    ]);
});

it('reports a wrong name and a wrong namespace on different classes of one file', function () {
    $contents = "<?php\n\nnamespace Studeo\\Blog\\Services {\n    final class Dual {}\n    final class Odd {}\n}\n\nnamespace Elsewhere {\n    final class Dual {}\n}\n";

    $found = runRule(FlatModules::options(), [...$this->root, 'Services/Dual.php' => $contents]);

    expect(summarise($found))->toBe(['modules/Blog/Services/Dual.php:5 Studeo\\Blog\\Services\\Odd', 'modules/Blog/Services/Dual.php:9 Elsewhere\\Dual'])
        ->and($found[0]->message)->toContain('class Odd is in Dual.php')
        ->and($found[1]->message)->toContain('namespace Elsewhere does not match');
});

it('skips an anonymous class and still checks a named class beside it', function () {
    $body = "final class Anon\n{\n    public function make(): object\n    {\n        return new class {};\n    }\n}\n\nfinal class Beside {}";

    expect(summarise(runRule(FlatModules::options(), [...$this->root, 'Services/Anon.php' => phpFile($body, 'Studeo\\Blog\\Services')])))->toBe([
        'modules/Blog/Services/Anon.php:14 Studeo\\Blog\\Services\\Beside',
    ]);
});

it('reports a class in the global namespace with an empty namespace in the message', function () {
    $found = runRule(FlatModules::options(), [...$this->root, 'Services/Plain.php' => "<?php\n\nfinal class Plain {}\n"]);

    expect(summarise($found))->toBe(['modules/Blog/Services/Plain.php:3 Plain'])
        ->and($found[0]->message)->toContain('namespace  does not match')->toContain('implies Studeo\\Blog\\Services');
});

it('finds a class declared inside a function body', function () {
    $body = "function make(): void\n{\n    final class Inner {}\n}";

    expect(summarise(runRule(FlatModules::options(), [...$this->root, 'Services/Nested.php' => phpFile($body, 'Studeo\\Blog\\Services')])))->toBe([
        'modules/Blog/Services/Nested.php:8 Studeo\\Blog\\Services\\Inner',
    ]);
});

it('reports a root modules directory with the module.json inside it, and not as an unknown directory', function () {
    $found = runRule(FlatModules::options(), [...$this->root, 'modules/Inner/module.json' => '{}']);

    expect(summarise($found))->toBe(['modules/Blog/modules/Inner/module.json:1 modules/Inner/module.json', 'modules/Blog/modules:1 modules'])
        ->and($found[0]->message)->toContain('nested module')
        ->and($found[1]->message)->toContain('nested module');
});

it('orders findings by check, then by sorted path within a check', function () {
    $files = [...$this->everything, 'Services/modules/readme.txt' => 'x', 'Models/Worse.php' => phpFile('final class Worse {}', 'Elsewhere')];

    expect(summarise(runRule(FlatModules::options(), $files)))->toBe([
        'modules/Blog/module.json:1 module.json',
        'modules/Blog/stray.php:1 stray.php',
        'modules/Blog/Http:1 Http',
        'modules/Blog/Services/Inner/module.json:1 Services/Inner/module.json',
        'modules/Blog/Services/modules:1 Services/modules',
        'modules/Blog/Actions/Bad.php:6 Elsewhere\\Bad',
        'modules/Blog/Models/Worse.php:6 Elsewhere\\Worse',
        'modules/Blog/Services/Bad.php:6 Elsewhere\\Bad',
    ]);
});

it('names custom class and data directories in the unknown-directory message', function () {
    $options = FlatModules::options(classDirectories: ['Actions', 'Observers'], dataDirectories: ['storage', 'config']);

    $found = runRule($options, [...$this->root, 'Http/readme.txt' => 'x']);

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('Class directories: Actions Observers. Other directories: storage config.');
});

it('narrows each of the five checks to the scope', function (array $scope, array $expected) {
    expect(summarise(runRule(FlatModules::options(scope: $scope), $this->everything)))->toBe($expected);
})->with([
    'the manifest' => [['module.json'], ['modules/Blog/module.json:1 module.json']],
    'the stray file' => [['stray.php'], ['modules/Blog/stray.php:1 stray.php']],
    'the unknown directory' => [['Http'], ['modules/Blog/Http:1 Http']],
    'the nested module and one class directory' => [['Services'], ['modules/Blog/Services/Inner/module.json:1 Services/Inner/module.json', 'modules/Blog/Services/Bad.php:6 Elsewhere\\Bad']],
    'a class directory without the other one' => [['Actions'], ['modules/Blog/Actions/Bad.php:6 Elsewhere\\Bad']],
    'two scopes' => [['Actions', 'Http'], ['modules/Blog/Http:1 Http', 'modules/Blog/Actions/Bad.php:6 Elsewhere\\Bad']],
]);

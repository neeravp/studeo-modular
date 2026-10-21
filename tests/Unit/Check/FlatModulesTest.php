<?php

use Studeo\Check\Engine\Severity;
use Studeo\Check\Exceptions\InvalidRuleConfigurationException;
use Studeo\Modular\Check\DefaultRules;
use Studeo\Modular\Check\ModuleLayout;
use Studeo\Modular\Check\Rules\FlatModules;

/**
 * A module root that passes: manifest, provider, class directories, data directories.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function flatModule(array $extra = []): array
{
    return [
        'module.json' => '{}',
        'README.md' => 'notes',
        'BlogServiceProvider.php' => phpFile('final class BlogServiceProvider {}', 'Studeo\\Blog'),
        'Actions/Enrollment/EnrollAction.php' => phpFile('final class EnrollAction {}', 'Studeo\\Blog\\Actions\\Enrollment'),
        'Models/Post.php' => phpFile('final class Post {}', 'Studeo\\Blog\\Models'),
        'Livewire/Pages/Home.php' => phpFile('final class Home {}', 'Studeo\\Blog\\Livewire\\Pages'),
        'config/blog.php' => "<?php\n\nreturn [];\n",
        'resources/views/home.blade.php' => '<div></div>',
        'tests/Feature/PostTest.php' => "<?php\n\nit('works', fn () => expect(true)->toBeTrue());\n",
        ...$extra,
    ];
}

it('passes a clean module', function () {
    expect(runRule(FlatModules::options(), flatModule()))->toBe([]);
});

it('has the id flat-modules and runs by default', function () {
    $names = array_map(fn ($rule): string => $rule->name(), (new DefaultRules)->rules()->rules());

    expect(FlatModules::options()->name())->toBe('flat-modules')
        ->and($names)->toContain('flat-modules');
});

it('accepts every directory of the shared layout list', function () {
    $files = ['module.json' => '{}'];

    foreach ([...ModuleLayout::CLASS_DIRECTORIES, ...ModuleLayout::DATA_DIRECTORIES] as $directory) {
        $files["{$directory}/notes.txt"] = 'x';
    }

    expect(runRule(FlatModules::options(), $files))->toBe([]);
});

it('requires module.json', function () {
    $files = flatModule();
    unset($files['module.json']);

    $found = runRule(FlatModules::options(), $files);

    expect(summarise($found))->toBe(['modules/Blog/module.json:1 module.json'])
        ->and($found[0]->rule)->toBe('flat-modules')
        ->and($found[0]->severity)->toBe(Severity::Error)
        ->and($found[0]->message)->toContain('manifest');
});

it('refuses a PHP file in the root other than the module provider', function (string $file) {
    $found = runRule(FlatModules::options(), flatModule([$file => "<?php\n"]));

    expect(summarise($found))->toBe(["modules/Blog/{$file}:1 {$file}"])
        ->and($found[0]->message)->toContain($file)->toContain('BlogServiceProvider.php');
})->with(['Helpers.php', 'AccountServiceProvider.php']);

it('ignores non-PHP files in the root', function () {
    expect(runRule(FlatModules::options(), flatModule(['package.json' => '{}', 'notes.txt' => 'x'])))->toBe([]);
});

it('refuses a root directory outside the layout and names both lists', function (string $directory) {
    $found = runRule(FlatModules::options(), flatModule(["{$directory}/readme.txt" => 'x']));

    expect(summarise($found))->toBe(["modules/Blog/{$directory}:1 {$directory}"])
        ->and($found[0]->message)->toContain($directory)->toContain('Actions')->toContain('Services')->toContain('config')->toContain('tests');
})->with(['Http', 'Console', 'src', 'Requests', 'Providers', 'Observers', 'Casts']);

it('reports a forbidden directory once however many files it holds', function () {
    $found = runRule(FlatModules::options(), flatModule(['Http/A.php' => phpFile('final class A {}', 'Studeo\\Blog\\Http'), 'Http/Controllers/B.php' => phpFile('final class B {}', 'Studeo\\Blog\\Http\\Controllers')]));

    expect(summarise($found))->toBe(['modules/Blog/Http:1 Http']);
});

it('refuses a class whose namespace is not the one its path implies', function (string $path, string $namespace, string $class, string $expected) {
    $found = runRule(FlatModules::options(), flatModule([$path => phpFile("final class {$class} {}", $namespace)]));

    expect(summarise($found))->toBe(["modules/Blog/{$path}:6 {$namespace}\\{$class}"])
        ->and($found[0]->message)->toContain($expected);
})->with([
    'sub-folder dropped' => ['Services/Billing/Invoice.php', 'Studeo\\Blog\\Services', 'Invoice', 'Studeo\\Blog\\Services\\Billing'],
    'other directory' => ['Services/Invoice.php', 'Studeo\\Blog\\Actions', 'Invoice', 'Studeo\\Blog\\Services'],
    'old Http namespace' => ['Controllers/PostController.php', 'Studeo\\Blog\\Http\\Controllers', 'PostController', 'Studeo\\Blog\\Controllers'],
    'other module namespace' => ['Models/Tag.php', 'Studeo\\Shop\\Models', 'Tag', 'Studeo\\Blog\\Models'],
]);

it('refuses a class whose name differs from its file name', function () {
    $found = runRule(FlatModules::options(), flatModule(['Services/Invoice.php' => phpFile('final class Bill {}', 'Studeo\\Blog\\Services')]));

    expect(summarise($found))->toBe(['modules/Blog/Services/Invoice.php:6 Studeo\\Blog\\Services\\Bill'])
        ->and($found[0]->message)->toContain('Bill')->toContain('Invoice.php');
});

it('checks interfaces, traits and enums like classes', function (string $declaration) {
    $found = runRule(FlatModules::options(), flatModule(['Contracts/Thing.php' => phpFile($declaration, 'Studeo\\Blog\\Contracts')]));

    expect($found)->toHaveCount(1);
})->with(['interface Other {}', 'trait Other {}', 'enum Other {}']);

it('exempts Livewire lightning entries and blade files from the namespace rule', function () {
    $files = flatModule([
        'Livewire/⚡create-post.blade.php' => "<?php\n\nnamespace Elsewhere;\n\nnew class {};\n",
        'Livewire/⚡edit/edit.php' => phpFile('final class Wrong {}', 'Elsewhere'),
        'Livewire/Pages/home.blade.php' => phpFile('final class Wrong {}', 'Elsewhere'),
    ]);

    expect(runRule(FlatModules::options(), $files))->toBe([]);
});

it('refuses a module.json below the root', function () {
    $found = runRule(FlatModules::options(), flatModule(['Services/Inner/module.json' => '{}']));

    expect(summarise($found))->toBe(['modules/Blog/Services/Inner/module.json:1 Services/Inner/module.json'])
        ->and($found[0]->message)->toContain('nested module');
});

it('refuses a modules directory below the root', function (string $directory) {
    $found = runRule(FlatModules::options(), flatModule(["{$directory}/Inner/readme.txt" => 'x']));

    expect(summarise($found))->toBe(["modules/Blog/{$directory}:1 {$directory}"])
        ->and($found[0]->message)->toContain('nested module');
})->with(['Services/modules', 'modules', 'database/modules']);

it('takes class and data directories from options', function () {
    $files = flatModule(['Observers/readme.txt' => 'x', 'storage/readme.txt' => 'x']);

    $found = runRule(FlatModules::options(classDirectories: ['Actions', 'Livewire', 'Models', 'Observers'], dataDirectories: ['config', 'resources', 'storage', 'tests']), $files);

    expect($found)->toBe([]);
});

it('refuses a directory the options leave out', function () {
    $found = runRule(FlatModules::options(classDirectories: ['Actions', 'Livewire']), flatModule());

    expect(summarise($found))->toBe(['modules/Blog/Models:1 Models']);
});

it('limits what it reports with scope', function () {
    $files = flatModule(['Http/readme.txt' => 'x', 'Actions/Bad.php' => phpFile('final class Bad {}', 'Elsewhere')]);

    expect(summarise(runRule(FlatModules::options(scope: ['Actions']), $files)))->toBe(['modules/Blog/Actions/Bad.php:6 Elsewhere\\Bad'])
        ->and(summarise(runRule(FlatModules::options(), $files)))->toHaveCount(2);
});

it('rejects invalid options naming the rule and the option', function (array $options, string $option) {
    expect(fn () => FlatModules::options(...$options))
        ->toThrow(InvalidRuleConfigurationException::class, "[flat-modules] option [{$option}]");
})->with([
    'empty class directories' => [['classDirectories' => []], 'classDirectories'],
    'blank class directory' => [['classDirectories' => ['Actions', ' ']], 'classDirectories'],
    'non-string class directory' => [['classDirectories' => [7]], 'classDirectories'],
    'nested class directory' => [['classDirectories' => ['Http/Controllers']], 'classDirectories'],
    'empty data directories' => [['dataDirectories' => []], 'dataDirectories'],
    'backslash data directory' => [['dataDirectories' => ['config\\x']], 'dataDirectories'],
    'empty scope' => [['scope' => []], 'scope'],
]);

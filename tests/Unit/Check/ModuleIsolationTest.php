<?php

use Studeo\Check\Exceptions\InvalidRuleConfigurationException;
use Studeo\Modular\Check\Rules\ModuleIsolation;

beforeEach(function () {
    $this->others = ['Account' => 'Studeo\\Account', 'Billing' => 'Acme\\Billing'];
});

it('flags a reference to another module outside Contracts, Data and Events', function () {
    $files = ['Actions/Run.php' => phpFile('final class Run { public function go(User $user) {} }', uses: 'use Studeo\\Account\\Models\\User;')];

    $found = runRule(ModuleIsolation::options(), $files, $this->others);

    expect(summarise($found))->toBe(['modules/Blog/Actions/Run.php:5 Studeo\\Account\\Models\\User'])
        ->and($found[0]->rule)->toBe('module-isolation')
        ->and($found[0]->message)->toContain('Account')->toContain('Contracts, Data, Events')->toContain('domain event');
});

it('resolves a short name to the full class name before judging it', function () {
    $files = ['Actions/Run.php' => phpFile('final class Run { public function go() { return Billing\\Invoice::query(); } }', uses: 'use Acme\\Billing;')];

    expect(summarise(runRule(ModuleIsolation::options(), $files, $this->others)))->toContain('modules/Blog/Actions/Run.php:6 Acme\\Billing\\Invoice');
});

it('sees an import that no code uses and a grouped import', function () {
    $files = ['Actions/Run.php' => phpFile('final class Run {}', uses: 'use Acme\\Billing\\{Models\\Invoice, Data\\Line};')];

    expect(summarise(runRule(ModuleIsolation::options(), $files, $this->others)))->toBe(['modules/Blog/Actions/Run.php:5 Acme\\Billing\\Models\\Invoice']);
});

it('allows the published namespaces', function (string $name) {
    $files = ['Actions/Run.php' => phpFile('final class Run {}', uses: "use Studeo\\Account\\{$name};")];

    expect(runRule(ModuleIsolation::options(), $files, $this->others))->toBe([]);
})->with(['Contracts\\Lookup', 'Data\\UserData', 'Events\\Registered']);

it('allows references to the module itself and to the framework', function () {
    $files = ['Actions/Run.php' => phpFile('final class Run {}', uses: "use Studeo\\Blog\\Models\\Post;\nuse Illuminate\\Support\\Str;\nuse App\\Models\\User;")];

    expect(runRule(ModuleIsolation::options(), $files, $this->others))->toBe([]);
});

it('does not confuse a module with one whose name starts the same', function () {
    $files = ['Actions/Run.php' => phpFile('final class Run {}', uses: 'use Studeo\\AccountExtras\\Models\\Thing;')];

    expect(runRule(ModuleIsolation::options(), $files, $this->others))->toBe([]);
});

it('checks the whole module by default and takes a scope from options', function () {
    $files = ['Models/Post.php' => phpFile('final class Post {}', 'Studeo\\Blog\\Models', 'use Studeo\\Account\\Models\\User;')];

    expect(runRule(ModuleIsolation::options(), $files, $this->others))->toHaveCount(1)
        ->and(runRule(ModuleIsolation::options(scope: ['Actions']), $files, $this->others))->toBe([]);
});

it('takes the allowed namespaces from options', function () {
    $files = ['Actions/Run.php' => phpFile('final class Run {}', uses: 'use Studeo\\Account\\Models\\User;')];

    expect(runRule(ModuleIsolation::options(allowed: ['Models']), $files, $this->others))->toBe([]);
});

it('rejects invalid options naming the rule and the option', function (array $options, string $option) {
    expect(fn () => ModuleIsolation::options(...$options))
        ->toThrow(InvalidRuleConfigurationException::class, "[module-isolation] option [{$option}]");
})->with([
    'empty allowed list' => [['allowed' => []], 'allowed'],
    'blank allowed name' => [['allowed' => ['']], 'allowed'],
    'empty scope' => [['scope' => []], 'scope'],
]);

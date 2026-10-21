<?php

use Studeo\Modular\Check\Rules\ModuleIsolation;

beforeEach(function () {
    $this->others = ['Account' => 'Studeo\\Account', 'Billing' => 'Acme\\Billing'];
    $this->found = fn (array $files, ?ModuleIsolation $rule = null): array => runRule($rule ?? ModuleIsolation::options(), $files, $this->others);
    $this->action = fn (string $body, string $uses = ''): array => ['Actions/Run.php' => phpFile($body, 'Studeo\\Blog\\Actions', $uses)];
});

it('flags a foreign class named in each position', function (string $body) {
    expect(summarise(($this->found)(($this->action)($body))))->toBe(['modules/Blog/Actions/Run.php:6 Studeo\\Account\\Services\\Thing']);
})->with([
    'extends' => ['final class Run extends \\Studeo\\Account\\Services\\Thing {}'],
    'implements' => ['final class Run implements \\Studeo\\Account\\Services\\Thing {}'],
    'new' => ['final class Run { public function go() { return new \\Studeo\\Account\\Services\\Thing; } }'],
    'static call' => ['final class Run { public function go() { return \\Studeo\\Account\\Services\\Thing::make(); } }'],
    'instanceof' => ['final class Run { public function go($x) { return $x instanceof \\Studeo\\Account\\Services\\Thing; } }'],
    'catch' => ['final class Run { public function go() { try {} catch (\\Studeo\\Account\\Services\\Thing $e) {} } }'],
    'type hint' => ['final class Run { public function go(\\Studeo\\Account\\Services\\Thing $thing) {} }'],
    'attribute' => ['#[\\Studeo\\Account\\Services\\Thing] final class Run {}'],
]);

it('reports the whole finding for a foreign class', function () {
    $found = ($this->found)(($this->action)('final class Run extends \\Studeo\\Account\\Models\\User {}'));

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('module-isolation')
        ->and($found[0]->file)->toBe('modules/Blog/Actions/Run.php')
        ->and($found[0]->line)->toBe(6)
        ->and($found[0]->subject)->toBe('Studeo\\Account\\Models\\User')
        ->and($found[0]->severity->name)->toBe('Error')
        ->and($found[0]->message)->toBe('references Studeo\\Account\\Models\\User from module Account. Cross-module use is limited to: Contracts, Data, Events. Use a Contract + DTO or a domain event.');
});

it('lists the configured namespaces in the message', function () {
    $files = ($this->action)('final class Run extends \\Studeo\\Account\\Models\\User {}');

    expect(($this->found)($files, ModuleIsolation::options(allowed: ['Services', 'Data']))[0]->message)
        ->toBe('references Studeo\\Account\\Models\\User from module Account. Cross-module use is limited to: Services, Data. Use a Contract + DTO or a domain event.');
});

it('reports two different foreign classes in the order the file names them', function () {
    $body = "final class Run\n{\n    public function go()\n    {\n        new \\Acme\\Billing\\Models\\Zeta;\n        new \\Studeo\\Account\\Models\\Alpha;\n    }\n}";

    expect(summarise(($this->found)(($this->action)($body))))->toBe([
        'modules/Blog/Actions/Run.php:10 Acme\\Billing\\Models\\Zeta',
        'modules/Blog/Actions/Run.php:11 Studeo\\Account\\Models\\Alpha',
    ]);
});

it('reports a foreign class named twice once, at the first line', function () {
    $body = "final class Run\n{\n    public function a(\\Studeo\\Account\\Models\\User \$u) {}\n    public function b(\\Studeo\\Account\\Models\\User \$u) {}\n}";

    expect(summarise(($this->found)(($this->action)($body))))->toBe(['modules/Blog/Actions/Run.php:8 Studeo\\Account\\Models\\User']);
});

it('reports a group-imported class that is also used at the line where it is used', function () {
    $body = "final class Run\n{\n    public function go()\n    {\n        new Early;\n    }\n}";

    expect(summarise(($this->found)(($this->action)($body, 'use Acme\\Billing\\{Models\\Early};'))))->toBe(['modules/Blog/Actions/Run.php:10 Acme\\Billing\\Models\\Early']);
});

it('reports an unused group item after the names of the code, whatever its line', function () {
    $body = "final class Run\n{\n    public function go()\n    {\n        new \\Studeo\\Account\\Models\\Used;\n    }\n}";

    expect(summarise(($this->found)(($this->action)($body, 'use Acme\\Billing\\{Models\\Unused};'))))->toBe([
        'modules/Blog/Actions/Run.php:10 Studeo\\Account\\Models\\Used',
        'modules/Blog/Actions/Run.php:5 Acme\\Billing\\Models\\Unused',
    ]);
});

it('judges only the first segment of the remainder', function (string $name, array $expected) {
    expect(summarise(($this->found)(($this->action)('final class Run {}', "use {$name};"))))->toBe($expected);
})->with([
    'nested under an unpublished segment' => ['Studeo\\Account\\Models\\Deep\\User', ['modules/Blog/Actions/Run.php:5 Studeo\\Account\\Models\\Deep\\User']],
    'nested under a published segment' => ['Studeo\\Account\\Data\\Deep\\User', []],
    'the published segment itself' => ['Studeo\\Account\\Data', []],
    'the module namespace itself' => ['Studeo\\Account', []],
]);

it('does not flag names that no module owns or that resolve into the module', function () {
    $body = "final class Run extends \\Studeo\\Blog\\Models\\Base\n{\n    public function go()\n    {\n        self::a();\n        static::b();\n        parent::c();\n        strlen('x');\n        return namespace\\Local::make() ?? PHP_EOL;\n    }\n}";

    expect(($this->found)(($this->action)($body, 'use Illuminate\\Support\\Str;')))->toBe([]);
});

it('does not flag the unresolved fragments of a group use', function () {
    expect(summarise(($this->found)(($this->action)('final class Run {}', 'use Acme\\Billing\\{Models\\Invoice, Data\\Line};'))))->toBe(['modules/Blog/Actions/Run.php:5 Acme\\Billing\\Models\\Invoice']);
});

it('checks the files of a module in path order and each file in the order of its names', function () {
    $files = [
        'Services/Zed.php' => phpFile('final class Zed extends \\Acme\\Billing\\Models\\One {}', 'Studeo\\Blog\\Services'),
        'Actions/Run.php' => phpFile('final class Run extends \\Acme\\Billing\\Models\\Two {}'),
    ];

    expect(summarise(($this->found)($files)))->toBe([
        'modules/Blog/Actions/Run.php:6 Acme\\Billing\\Models\\Two',
        'modules/Blog/Services/Zed.php:6 Acme\\Billing\\Models\\One',
    ]);
});

it('gives no finding for a file that does not parse or for an empty file, and still checks the others', function () {
    $files = [
        'Actions/Broken.php' => "<?php\n\n\$x = ;\n",
        'Actions/Empty.php' => '',
        'Actions/Open.php' => "<?php\n",
        'Actions/Run.php' => phpFile('final class Run extends \\Studeo\\Account\\Models\\User {}'),
    ];

    expect(summarise(($this->found)($files)))->toBe(['modules/Blog/Actions/Run.php:6 Studeo\\Account\\Models\\User']);
});

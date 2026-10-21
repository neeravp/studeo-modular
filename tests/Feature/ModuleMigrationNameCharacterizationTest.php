<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateMigrationAction;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(['resolve:CreateMigrationAction', 'resolve:ResolveModuleAction'], usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->fixture->modules('Account');
    $this->driver = $this->fixture->driver([CreateMigrationAction::class]);
    $this->prompt = "\n  What should the migration be named?\n❯ \n";
    $this->required = "   ERROR  Required.  \n\n\n";
    $this->missing = "The [name] argument must be given when the command is not interactive, e.g. module:migration create_flights_table --module=Blog.\n";
    $this->summary = fn (string $file): string => "Migration [<timestamp>_{$file}.php] created successfully in module [Account].\nPath: <modules>/Account/database/migrations/<timestamp>_{$file}.php\n";
    $this->run = function (array $input, array $answers = [], bool $interactive = true): array {
        $run = $this->driver->run('module:migration', ['--module' => 'Account', ...$input], $answers, $interactive);
        $run['stdout'] = preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}/', '<timestamp>', $run['stdout']);

        return $run;
    };
    $this->outcome = $this->fixture->outcome(...);
});

afterEach(fn () => $this->fixture->tearDown());

it('uses the name it is given without asking', function () {
    expect(($this->run)(['name' => 'create_a_table']))->toBe(($this->outcome)(0, ($this->summary)('create_a_table')));
});

it('keeps a name of 0 as a given name', function () {
    expect(($this->run)(['name' => '0']))->toBe(($this->outcome)(0, ($this->summary)('0')));
});

it('keeps a name that only looks blank after other characters', function () {
    expect(($this->run)(['name' => ' x ']))->toBe(($this->outcome)(0, ($this->summary)('x')));
});

it('asks for the name when it is omitted, empty or only whitespace', function (array $input) {
    expect(($this->run)($input, ['create_b_table']))->toBe(($this->outcome)(0, $this->prompt.($this->summary)('create_b_table'), 15));
})->with([
    'omitted' => [[]],
    'empty' => [['name' => '']],
    'spaces' => [['name' => '   ']],
    'a tab and a newline' => [['name' => "\t\n"]],
]);

it('fails with a named error and exit 1 when the name is missing or blank and nothing can be answered', function (array $input) {
    expect(($this->run)($input, [], false))->toBe(($this->outcome)(1, $this->missing));
})->with([
    'omitted' => [[]],
    'empty' => [['name' => '']],
    'spaces' => [['name' => ' ']],
]);

it('fails with Required when the prompt is answered with nothing', function () {
    expect(($this->run)([], ['']))->toBe(($this->outcome)(1, $this->prompt.$this->required, 1));
});

it('writes no migration when the name could not be had', function () {
    ($this->run)([], [], false);

    expect(File::glob($this->rootPath.'/Account/database/migrations/*'))->toBe([]);
});

it('passes the name and the flags on to the migration', function () {
    $run = ($this->run)(['name' => 'create_c_table', '--create' => 'cs', '--blank' => true]);

    expect($run)->toBe(($this->outcome)(0, ($this->summary)('create_c_table')));
});

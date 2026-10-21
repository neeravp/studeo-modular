<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateMailAction;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->fixture->modules('Account');
    $this->driver = $this->fixture->driver([CreateMailAction::class]);
    $this->resolved = ['resolve:CreateMailAction', 'resolve:ResolveModuleAction'];
    $this->head = "Mail [A] created successfully in module [Account].\nPath: <modules>/Account/Mail/A.php\n";
    $this->contentLines = fn (): array => preg_grep('/^\s+(markdown|view): /', explode("\n", File::get($this->rootPath.'/Account/Mail/A.php')));
    $this->outcome = fn (int $status, string $stdout, array $resolved, int $answered = 0, string $stderr = ''): array => [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'answered' => $answered,
        'resolved' => $resolved,
    ];
});

afterEach(fn () => $this->fixture->tearDown());

it('maps the state of --markdown to the template the mailable gets', function (array $option, string $view, array $lines) {
    $run = $this->driver->run('module:mail', ['name' => 'A', '--module' => 'Account', ...$option]);

    expect($run)->toBe(($this->outcome)(0, $this->head.$view, $this->resolved))
        ->and(array_values(($this->contentLines)()))->toBe($lines);
})->with([
    'absent' => [[], '', ["            view: 'account::mail.a',"]],
    'bare flag' => [['--markdown' => null], "View: <modules>/Account/resources/views/mail/a.blade.php\n", ["            markdown: 'account::mail.a',"]],
    'true' => [['--markdown' => true], "View: <modules>/Account/resources/views/mail/a.blade.php\n", ["            markdown: 'account::mail.a',"]],
    'empty value' => [['--markdown' => ''], "View: <modules>/Account/resources/views/mail/a.blade.php\n", ["            markdown: 'account::mail.a',"]],
    'a value' => [['--markdown' => 'mail.x'], "View: <modules>/Account/resources/views/mail/x.blade.php\n", ["            markdown: 'account::mail.x',"]],
    'zero' => [['--markdown' => '0'], "View: <modules>/Account/resources/views/0.blade.php\n", ["            markdown: 'account::0',"]],
]);

it('maps the state of --view to the template the mailable gets', function (array $option, string $view, array $lines) {
    $run = $this->driver->run('module:mail', ['name' => 'A', '--module' => 'Account', ...$option]);

    expect($run)->toBe(($this->outcome)(0, $this->head.$view, $this->resolved))
        ->and(array_values(($this->contentLines)()))->toBe($lines);
})->with([
    'absent' => [[], '', ["            view: 'account::mail.a',"]],
    'bare flag' => [['--view' => null], "View: <modules>/Account/resources/views/mail/a.blade.php\n", ["            view: 'account::mail.a',"]],
    'true' => [['--view' => true], "View: <modules>/Account/resources/views/mail/a.blade.php\n", ["            view: 'account::mail.a',"]],
    'empty value' => [['--view' => ''], "View: <modules>/Account/resources/views/mail/a.blade.php\n", ["            view: 'account::mail.a',"]],
    'a value' => [['--view' => 'mail.x'], "View: <modules>/Account/resources/views/mail/x.blade.php\n", ["            view: 'account::mail.x',"]],
    'zero' => [['--view' => '0'], "View: <modules>/Account/resources/views/0.blade.php\n", ["            view: 'account::0',"]],
]);

it('lets --markdown win when both templates are given', function () {
    $run = $this->driver->run('module:mail', ['name' => 'A', '--module' => 'Account', '--view' => 'v', '--markdown' => 'm']);

    expect($run)->toBe(($this->outcome)(0, $this->head."View: <modules>/Account/resources/views/m.blade.php\n", $this->resolved))
        ->and(array_values(($this->contentLines)()))->toBe(["            markdown: 'account::m',"]);
});

it('marks the mailable as queued and prints nothing more', function () {
    $run = $this->driver->run('module:mail', ['name' => 'A', '--module' => 'Account', '--queued' => true]);

    expect($run)->toBe(($this->outcome)(0, $this->head, $this->resolved))
        ->and(File::get($this->rootPath.'/Account/Mail/A.php'))->toContain('class A extends Mailable implements ShouldQueue');
});

it('keeps a mailable that is not queued plain', function () {
    $this->driver->run('module:mail', ['name' => 'A', '--module' => 'Account']);

    expect(File::get($this->rootPath.'/Account/Mail/A.php'))->not->toContain('ShouldQueue');
});

it('asks for the name when it is omitted or blank, and prints the question before the summary', function (array $input) {
    $prompt = "\n  What should the mailable be named?\n❯ \n";
    $run = $this->driver->run('module:mail', ['--module' => 'Account', ...$input], ['B']);

    expect($run)->toBe(($this->outcome)(0, $prompt."Mail [B] created successfully in module [Account].\nPath: <modules>/Account/Mail/B.php\n", $this->resolved, 2));
})->with([[[]], [['name' => '']], [['name' => '   ']]]);

it('refuses a name of 0 as a given name, without asking and without writing', function () {
    $run = $this->driver->run('module:mail', ['name' => '0', '--module' => 'Account']);

    expect($run['status'])->toBe(1)->and($run['answered'])->toBe(0)->and($run['stdout'])->toBe("Invalid mail name [0].\n")
        ->and(File::exists($this->rootPath.'/Account/Mail/0.php'))->toBeFalse();
});

it('fails with a named error and exit 1 when the name is missing and nothing can be answered', function () {
    $run = $this->driver->run('module:mail', ['--module' => 'Account'], [], false);

    expect($run)->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:mail OrderShipped --module=Blog.\n", $this->resolved));
});

it('reads the options after the name, so a missing name fails before the options matter', function () {
    $run = $this->driver->run('module:mail', ['--module' => 'Account', '--markdown' => 'mail.x'], [], false);

    expect($run)->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:mail OrderShipped --module=Blog.\n", $this->resolved))
        ->and(File::exists($this->rootPath.'/Account/resources/views/mail/x.blade.php'))->toBeFalse();
});

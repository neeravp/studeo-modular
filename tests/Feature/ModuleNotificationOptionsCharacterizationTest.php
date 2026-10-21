<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateNotificationAction;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->fixture->modules('Account');
    $this->driver = $this->fixture->driver([CreateNotificationAction::class]);
    $this->resolved = ['resolve:CreateNotificationAction', 'resolve:ResolveModuleAction'];
    $this->head = "Notification [A] created successfully in module [Account].\nPath: <modules>/Account/Notifications/A.php\n";
    $this->mailLines = fn (): array => array_values(preg_grep('/->markdown\(/', explode("\n", File::get($this->rootPath.'/Account/Notifications/A.php'))));
    $this->outcome = fn (int $status, string $stdout, array $resolved, int $answered = 0, string $stderr = ''): array => [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'answered' => $answered,
        'resolved' => $resolved,
    ];
});

afterEach(fn () => $this->fixture->tearDown());

it('maps the state of --markdown to the template the notification gets', function (array $option, string $view, array $lines) {
    $run = $this->driver->run('module:notification', ['name' => 'A', '--module' => 'Account', ...$option]);

    expect($run)->toBe(($this->outcome)(0, $this->head.$view, $this->resolved))
        ->and(($this->mailLines)())->toBe($lines);
})->with([
    'absent' => [[], '', []],
    'bare flag' => [['--markdown' => null], "Markdown View: <modules>/Account/resources/views/mail/a.blade.php\n", ["        return (new MailMessage)->markdown('account::mail.a');"]],
    'true' => [['--markdown' => true], "Markdown View: <modules>/Account/resources/views/mail/a.blade.php\n", ["        return (new MailMessage)->markdown('account::mail.a');"]],
    'empty value' => [['--markdown' => ''], "Markdown View: <modules>/Account/resources/views/mail/a.blade.php\n", ["        return (new MailMessage)->markdown('account::mail.a');"]],
    'a value' => [['--markdown' => 'n.x'], "Markdown View: <modules>/Account/resources/views/n/x.blade.php\n", ["        return (new MailMessage)->markdown('account::n.x');"]],
    'zero' => [['--markdown' => '0'], "Markdown View: <modules>/Account/resources/views/0.blade.php\n", ["        return (new MailMessage)->markdown('account::0');"]],
]);

it('marks the notification as queued and prints nothing more', function () {
    $run = $this->driver->run('module:notification', ['name' => 'A', '--module' => 'Account', '--queued' => true]);

    expect($run)->toBe(($this->outcome)(0, $this->head, $this->resolved))
        ->and(File::get($this->rootPath.'/Account/Notifications/A.php'))->toContain('implements ShouldQueue');
});

it('keeps a notification that is not queued plain', function () {
    $this->driver->run('module:notification', ['name' => 'A', '--module' => 'Account']);

    expect(File::get($this->rootPath.'/Account/Notifications/A.php'))->not->toContain('ShouldQueue');
});

it('asks for the name when it is omitted or blank, and prints the question before the summary', function (array $input) {
    $prompt = "\n  What should the notification be named?\n❯ \n";
    $run = $this->driver->run('module:notification', ['--module' => 'Account', ...$input], ['B']);

    expect($run)->toBe(($this->outcome)(0, $prompt."Notification [B] created successfully in module [Account].\nPath: <modules>/Account/Notifications/B.php\n", $this->resolved, 2));
})->with([[[]], [['name' => '']], [['name' => '   ']]]);

it('refuses a name of 0 as a given name, without asking and without writing', function () {
    $run = $this->driver->run('module:notification', ['name' => '0', '--module' => 'Account']);

    expect($run['status'])->toBe(1)->and($run['answered'])->toBe(0)->and($run['stdout'])->toBe("Invalid notification name [0].\n")
        ->and(File::exists($this->rootPath.'/Account/Notifications/0.php'))->toBeFalse();
});

it('fails with a named error and exit 1 when the name is missing and nothing can be answered', function () {
    $run = $this->driver->run('module:notification', ['--module' => 'Account'], [], false);

    expect($run)->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:notification InvoicePaid --module=Blog.\n", $this->resolved));
});

it('fails on the name before the markdown option is looked at', function () {
    $run = $this->driver->run('module:notification', ['--module' => 'Account', '--markdown' => 'n.x'], [], false);

    expect($run)->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:notification InvoicePaid --module=Blog.\n", $this->resolved))
        ->and(File::exists($this->rootPath.'/Account/resources/views/n/x.blade.php'))->toBeFalse();
});

<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateCommandAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\CommandGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->accountModule = new ModuleDefinition(
        name: 'Account',
        namespace: 'Studeo\\Account',
        path: $this->rootModulesPath.'/Account',
    );

    app(CreateModuleAction::class)->execute($this->accountModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

test('it creates a command with default signature based on module and name', function () {
    $action = app(CreateCommandAction::class);

    $path = $action->execute($this->accountModule, 'SendInvoices');

    expect($path)->toBe($this->accountModule->path.'/Commands/SendInvoices.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Commands;')
        ->toContain('class SendInvoices extends Command')
        ->toContain("protected \$signature = 'account:send-invoices';")
        ->toContain("protected \$description = 'Command description';")
        ->toContain('public function handle(): int');
});

test('it strips trailing Command suffix when deriving default signature', function () {
    $action = app(CreateCommandAction::class);

    $path = $action->execute($this->accountModule, 'SendInvoicesCommand');

    expect($path)->toBe($this->accountModule->path.'/Commands/SendInvoicesCommand.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('class SendInvoicesCommand extends Command')
        ->toContain("protected \$signature = 'account:send-invoices';");
});

test('it preserves explicit signature when provided in options', function () {
    $action = app(CreateCommandAction::class);

    $path = $action->execute(
        $this->accountModule,
        'SendInvoices',
        new CommandGenerationOptions(command: 'billing:dispatch-invoices')
    );

    $content = File::get($path);
    expect($content)
        ->toContain("protected \$signature = 'billing:dispatch-invoices';");
});

test('it derives kebab-case module prefix for multi-word modules', function () {
    $billingCycleModule = new ModuleDefinition(
        name: 'BillingCycle',
        namespace: 'Studeo\\BillingCycle',
        path: $this->rootModulesPath.'/BillingCycle',
    );
    app(CreateModuleAction::class)->execute($billingCycleModule);

    $action = app(CreateCommandAction::class);
    $path = $action->execute($billingCycleModule, 'ProcessRenewals');

    $content = File::get($path);
    expect($content)
        ->toContain("protected \$signature = 'billing-cycle:process-renewals';");
});

test('it creates Commands directory on demand if it does not exist', function () {
    File::deleteDirectory($this->accountModule->path.'/Commands');

    $action = app(CreateCommandAction::class);
    $path = $action->execute($this->accountModule, 'PruneOldRecords');

    expect(File::isDirectory($this->accountModule->path.'/Commands'))->toBeTrue()
        ->and(File::exists($path))->toBeTrue();
});

test('it sanitizes whitespace and slashes in command name', function () {
    $action = app(CreateCommandAction::class);

    $path = $action->execute($this->accountModule, '  /sync_audit_logs/  ');

    expect($path)->toBe($this->accountModule->path.'/Commands/SyncAuditLogs.php')
        ->and(File::exists($path))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateCommandAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'SendInvoices'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when command already exists in module', function () {
    $action = app(CreateCommandAction::class);

    $action->execute($this->accountModule, 'SendInvoices');

    expect(fn () => $action->execute($this->accountModule, 'SendInvoices'))
        ->toThrow(RuntimeException::class, 'Command [SendInvoices] already exists in module [Account].');
});

test('it throws an exception on invalid or empty command name', function () {
    $action = app(CreateCommandAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid command name [   ///   ].');
});

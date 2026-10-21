<?php

use Illuminate\Support\Facades\Process;
use Studeo\Modular\Console\Actions\InstallInertiaVueAction;
use Studeo\Modular\Console\Actions\InstallLivewireAction;
use Studeo\Modular\DataObjects\InertiaVueStatus;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

function inertiaStatus(array $missing, string $manager = 'npm'): InertiaVueStatus
{
    return new InertiaVueStatus(serverMajor: null, clientMajor: null, missing: $missing, hasPackageJson: true, packageManager: $manager);
}

it('lists the exact commands for what is missing', function () {
    $action = new InstallInertiaVueAction(installerRoot(), fn () => null);

    expect($action->commands(inertiaStatus(['inertiajs/inertia-laravel', 'vue', '@inertiajs/vue3'])))->toBe([
        ['composer', 'require', 'inertiajs/inertia-laravel:^3.0'],
        ['npm', 'install', 'vue@^3.5', '@inertiajs/vue3@^3.0', '@vitejs/plugin-vue', 'unplugin-vue-components'],
    ])
        ->and($action->commands(inertiaStatus(['inertiajs/inertia-laravel'])))->toBe([['composer', 'require', 'inertiajs/inertia-laravel:^3.0']])
        ->and($action->commands(inertiaStatus(['vue'], 'pnpm')))->toBe([['pnpm', 'add', 'vue@^3.5', '@inertiajs/vue3@^3.0', '@vitejs/plugin-vue', 'unplugin-vue-components']])
        ->and($action->commands(inertiaStatus([])))->toBe([]);
});

it('runs the commands in the project root', function () {
    Process::fake();
    $root = installerRoot();

    (new InstallInertiaVueAction($root, fn () => null))->execute(inertiaStatus(['inertiajs/inertia-laravel', 'vue']));

    Process::assertRan(fn ($process) => $process->command === ['composer', 'require', 'inertiajs/inertia-laravel:^3.0'] && $process->path === $root);
    Process::assertRan(fn ($process) => $process->command[0] === 'npm' && $process->path === $root);
});

it('checks Node before running anything', function () {
    Process::fake();
    $action = new InstallInertiaVueAction(installerRoot(), function () {
        throw new RuntimeException('Node.js 20.0.0+ is required');
    });

    expect(fn () => $action->execute(inertiaStatus(['vue'])))->toThrow(RuntimeException::class, 'Node.js 20.0.0+ is required');
    Process::assertNothingRan();
});

it('runs the real Node check when none is injected', function (string $version, bool $ok) {
    Process::fake(['*' => Process::result($version)]);
    $action = fn () => (new InstallInertiaVueAction(installerRoot()))->execute(inertiaStatus([]));

    $ok ? expect($action())->toBeNull() : expect($action)->toThrow(RuntimeException::class, 'Node.js 20.0.0+ is required');
})->with([['v20.11.0', true], ['v24.1.0', true], ['v18.19.0', false]]);

it('reports a missing Node', function () {
    Process::fake(['*' => Process::result(exitCode: 127)]);

    expect(fn () => (new InstallInertiaVueAction(installerRoot()))->execute(inertiaStatus(['vue'])))
        ->toThrow(RuntimeException::class, 'Node.js is required');
});

it('reports a failing command', function () {
    Process::fake(['*' => Process::result(errorOutput: 'no network', exitCode: 1)]);

    expect(fn () => (new InstallInertiaVueAction(installerRoot(), fn () => null))->execute(inertiaStatus(['inertiajs/inertia-laravel'])))
        ->toThrow(RuntimeException::class, 'Failed to install Inertia and Vue: no network');
});

it('requires Livewire 4 with Composer', function () {
    Process::fake();

    (new InstallLivewireAction(installerRoot()))->execute();

    Process::assertRan(fn ($process) => $process->command === ['composer', 'require', 'livewire/livewire:^4.0']);
    expect(InstallLivewireAction::COMMAND)->toBe(['composer', 'require', 'livewire/livewire:^4.0']);
});

it('reports a failing Livewire install', function () {
    Process::fake(['*' => Process::result(errorOutput: 'conflict', exitCode: 1)]);

    expect(fn () => (new InstallLivewireAction(installerRoot()))->execute())->toThrow(RuntimeException::class, 'Failed to install Livewire: conflict');
});

afterEach(fn () => cleanInstallerRoots());

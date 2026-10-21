<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Actions\CheckInertiaVueAction;
use Studeo\Modular\DataObjects\InertiaVueStatus;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

function inertiaCheck(string $root, ?string $server): InertiaVueStatus
{
    return (new CheckInertiaVueAction(app(Filesystem::class), $root, fn (): ?string => $server))->execute();
}

function packageJson(array $dependencies, array $dev = []): string
{
    return json_encode(['dependencies' => $dependencies, 'devDependencies' => $dev]);
}

it('reports a project with no package.json and no server package as missing everything', function () {
    $status = inertiaCheck(installerRoot(), null);

    expect($status->isInstalled())->toBeFalse()
        ->and($status->hasPackageJson)->toBeFalse()
        ->and($status->missing)->toBe(['package.json', 'inertiajs/inertia-laravel', 'vue', '@inertiajs/vue3'])
        ->and($status->serverMajor)->toBeNull()
        ->and($status->clientMajor)->toBeNull()
        ->and($status->isUnsupported())->toBeFalse();
});

it('reports Inertia 3 and Vue as installed', function () {
    $root = installerRoot(['package.json' => packageJson(['vue' => '^3.5.0'], ['@inertiajs/vue3' => '^3.0.0'])]);
    $status = inertiaCheck($root, 'v3.0.2');

    expect($status->isInstalled())->toBeTrue()
        ->and($status->missing)->toBe([])
        ->and($status->serverMajor)->toBe(3)
        ->and($status->clientMajor)->toBe(3)
        ->and($status->isUnsupported())->toBeFalse()
        ->and($status->hasPackageJson)->toBeTrue()
        ->and($status->packageManager)->toBe('npm');
});

it('names only what is missing', function () {
    $root = installerRoot(['package.json' => packageJson(['@inertiajs/vue3' => '^3.0.0'])]);

    expect(inertiaCheck($root, '3.0.0')->missing)->toBe(['vue'])
        ->and(inertiaCheck($root, null)->missing)->toBe(['inertiajs/inertia-laravel', 'vue']);
});

it('treats a v2 server package as unsupported', function () {
    $root = installerRoot(['package.json' => packageJson(['vue' => '^3.5.0', '@inertiajs/vue3' => '^3.0.0'])]);
    $status = inertiaCheck($root, '2.0.4');

    expect($status->serverMajor)->toBe(2)
        ->and($status->isUnsupported())->toBeTrue()
        ->and($status->unsupported())->toBe(['inertiajs/inertia-laravel']);
});

it('treats a v2 client package as unsupported, from the declared range', function () {
    $root = installerRoot(['package.json' => packageJson(['vue' => '^3.5.0', '@inertiajs/vue3' => '~2.1.0'])]);
    $status = inertiaCheck($root, '3.0.0');

    expect($status->clientMajor)->toBe(2)
        ->and($status->unsupported())->toBe(['@inertiajs/vue3']);
});

it('reads the installed client version from node_modules before the declared range', function () {
    $root = installerRoot([
        'package.json' => packageJson(['vue' => '^3.5.0', '@inertiajs/vue3' => '^3.0.0']),
        'node_modules/@inertiajs/vue3/package.json' => json_encode(['version' => '2.3.1']),
    ]);

    expect(inertiaCheck($root, '3.0.0')->clientMajor)->toBe(2);
});

it('reports both packages when both are v2', function () {
    $root = installerRoot(['package.json' => packageJson(['vue' => '^3.5.0', '@inertiajs/vue3' => '^2.0.0'])]);

    expect(inertiaCheck($root, '2.1.0')->unsupported())->toBe(['inertiajs/inertia-laravel', '@inertiajs/vue3']);
});

it('does not guess a major from a version it cannot read', function () {
    $root = installerRoot(['package.json' => packageJson(['vue' => '^3.5.0', '@inertiajs/vue3' => 'latest'])]);
    $status = inertiaCheck($root, 'dev-main');

    expect($status->serverMajor)->toBeNull()
        ->and($status->clientMajor)->toBeNull()
        ->and($status->isInstalled())->toBeTrue()
        ->and($status->isUnsupported())->toBeFalse();
});

it('picks the package manager from the lock file', function () {
    $root = installerRoot(['package.json' => '{}', 'pnpm-lock.yaml' => '']);

    expect(inertiaCheck($root, null)->packageManager)->toBe('pnpm');
});

it('treats a malformed package.json as empty', function () {
    $root = installerRoot(['package.json' => '{not json']);

    expect(inertiaCheck($root, '3.0.0')->missing)->toBe(['vue', '@inertiajs/vue3']);
});

it('writes nothing while inspecting', function () {
    $root = installerRoot(['package.json' => packageJson(['vue' => '^3.5.0'])]);
    $before = frontendSnapshot($root);

    inertiaCheck($root, null);

    expect(frontendSnapshot($root))->toBe($before);
});

afterEach(fn () => cleanInstallerRoots());

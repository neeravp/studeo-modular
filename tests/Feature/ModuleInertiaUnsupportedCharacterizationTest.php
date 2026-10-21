<?php

use Studeo\Modular\Tests\Support\FrontendCommandDriver;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->installer = new FrontendCommandDriver(app());
    $this->bind = $this->installer->bind(...);
    $this->drive = $this->installer->run(...);
});

afterEach(fn () => $this->installer->tearDown());

it('refuses an unsupported server major without asking', function () {
    ($this->bind)(readyFrontendRoot(), '2.0.4');
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Inertia inertiajs/inertia-laravel 2.x is installed, and this package supports Inertia 3 only. Nothing was changed. Upgrade it yourself with:',
            '  composer require inertiajs/inertia-laravel:^3.0',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction'],
        'processes' => [],
        'root' => [
            'package.json' => '0f32e3300b4fdbdfe4e94f38a68ec8d0',
            'resources/js/app.js' => '7f6af59b1b38d2d0a75da1b2d7181661',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => 'fe16dced34caf20ea1996be0811005aa',
        ],
        'component' => false,
    ]);
});

it('refuses an unsupported client major without asking', function () {
    ($this->bind)(installerRoot([
        'package.json' => json_encode(['dependencies' => ['vue' => '^3.5.0', '@inertiajs/vue3' => '^2.3.0']]),
        'vite.config.js' => 'modules/*/resources/js/Components',
        'resources/js/app.js' => 'modules/**/resources/js/Pages',
        'resources/views/app.blade.php' => '<html></html>',
    ]));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Inertia @inertiajs/vue3 2.x is installed, and this package supports Inertia 3 only. Nothing was changed. Upgrade it yourself with:',
            '  npm install @inertiajs/vue3@^3.0',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction'],
        'processes' => [],
        'root' => [
            'package.json' => 'daa253d03270e0914ce01880daa722dc',
            'resources/js/app.js' => '8ea6b86712d9730437126cc6fe4babb8',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => '334a335b96a53011f82a63bb87cf7eb6',
        ],
        'component' => false,
    ]);
});

it('names both unsupported packages', function () {
    ($this->bind)(installerRoot([
        'package.json' => json_encode(['dependencies' => ['vue' => '^3.5.0', '@inertiajs/vue3' => '^2.3.0']]),
        'vite.config.js' => 'modules/*/resources/js/Components',
        'resources/js/app.js' => 'modules/**/resources/js/Pages',
        'resources/views/app.blade.php' => '<html></html>',
    ]), '2.0.4');
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Inertia inertiajs/inertia-laravel 2.x, @inertiajs/vue3 2.x is installed, and this package supports Inertia 3 only. Nothing was changed. Upgrade it yourself with:',
            '  composer require inertiajs/inertia-laravel:^3.0',
            '  npm install @inertiajs/vue3@^3.0',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction'],
        'processes' => [],
        'root' => [
            'package.json' => 'daa253d03270e0914ce01880daa722dc',
            'resources/js/app.js' => '8ea6b86712d9730437126cc6fe4babb8',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => '334a335b96a53011f82a63bb87cf7eb6',
        ],
        'component' => false,
    ]);
});

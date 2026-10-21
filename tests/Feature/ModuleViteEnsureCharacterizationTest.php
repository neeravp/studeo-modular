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

it('asks nothing when nothing is pending', function () {
    ($this->bind)();
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction', 'resolve:CheckViteConfigurationAction'],
        'processes' => [],
        'root' => [
            'package.json' => '0f32e3300b4fdbdfe4e94f38a68ec8d0',
            'resources/js/app.js' => '7f6af59b1b38d2d0a75da1b2d7181661',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => 'fe16dced34caf20ea1996be0811005aa',
        ],
        'component' => true,
    ]);
});

it('prints the pending files and still creates the component without a terminal', function () {
    ($this->bind)(readyFrontendRoot(['vite.config.js', 'resources/js/app.js', 'resources/views/app.blade.php']));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], [], false);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'The Vite and Inertia root files need these changes:',
            'create resources/views/app.blade.php',
            '<contents 201b40fc>',
            'create vite.config.js',
            '<contents 3127d1ce>',
            'create resources/js/app.js',
            '<contents eb239af4>',
            'Nothing was written to your project. Create or change the files above yourself.',
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [
            'resolve:CheckInertiaVueAction',
            'resolve:InstallInertiaVueAction',
            'resolve:CheckViteConfigurationAction',
            'resolve:ScaffoldInertiaFrontendAction',
        ],
        'processes' => [],
        'root' => [
            'package.json' => '0f32e3300b4fdbdfe4e94f38a68ec8d0',
        ],
        'component' => true,
    ]);
});

it('prints the pending files and writes nothing after a no', function () {
    ($this->bind)(readyFrontendRoot(['vite.config.js', 'resources/js/app.js', 'resources/views/app.blade.php']));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['no'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'The Vite and Inertia root files need these changes:',
            'create resources/views/app.blade.php',
            '<contents 201b40fc>',
            'create vite.config.js',
            '<contents 3127d1ce>',
            'create resources/js/app.js',
            '<contents eb239af4>',
            '  Write the Vite and Inertia files listed above? (yes/no) [yes]',
            '❯ ',
            'Nothing was written to your project. Create or change the files above yourself.',
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 3,
        'resolved' => [
            'resolve:CheckInertiaVueAction',
            'resolve:InstallInertiaVueAction',
            'resolve:CheckViteConfigurationAction',
            'resolve:ScaffoldInertiaFrontendAction',
        ],
        'processes' => [],
        'root' => [
            'package.json' => '0f32e3300b4fdbdfe4e94f38a68ec8d0',
        ],
        'component' => true,
    ]);
});

it('writes the pending files after a yes', function () {
    ($this->bind)(readyFrontendRoot(['vite.config.js', 'resources/js/app.js', 'resources/views/app.blade.php']));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'The Vite and Inertia root files need these changes:',
            'create resources/views/app.blade.php',
            '<contents 201b40fc>',
            'create vite.config.js',
            '<contents 3127d1ce>',
            'create resources/js/app.js',
            '<contents eb239af4>',
            '  Write the Vite and Inertia files listed above? (yes/no) [yes]',
            '❯ ',
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 4,
        'resolved' => [
            'resolve:CheckInertiaVueAction',
            'resolve:InstallInertiaVueAction',
            'resolve:CheckViteConfigurationAction',
            'resolve:ScaffoldInertiaFrontendAction',
        ],
        'processes' => [],
        'root' => [
            'package.json' => '0f32e3300b4fdbdfe4e94f38a68ec8d0',
            'resources/js/app.js' => '540cebd0557ee78301c603a78a517037',
            'resources/views/app.blade.php' => '05c43d0337a5e506fe0ba151a580c2be',
            'vite.config.js' => '2e6c6006ceacede252c625f80ae7f34f',
        ],
        'component' => true,
    ]);
});

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

it('asks nothing when Inertia and Vue are installed', function () {
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

it('prints the install commands and fails without a terminal', function () {
    ($this->bind)(readyFrontendRoot(['package.json']));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], [], false);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Inertia 3 and Vue are required to generate Vue components. To install them:',
            '  npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components',
            'Inertia 3 and Vue are not installed. Nothing was changed. Run the commands above yourself, then run this command again.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction'],
        'processes' => [],
        'root' => [
            'resources/js/app.js' => '7f6af59b1b38d2d0a75da1b2d7181661',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => 'fe16dced34caf20ea1996be0811005aa',
        ],
        'component' => false,
    ]);
});

it('prints the install commands and fails after a no', function () {
    ($this->bind)(readyFrontendRoot(['package.json']));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['no'], true);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Inertia 3 and Vue are required to generate Vue components. To install them:',
            '  npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components',
            '',
            '  Inertia 3 and Vue are not installed in this project. Install them with the commands above? (yes/no) [yes]',
            '❯ ',
            'Inertia 3 and Vue are not installed. Nothing was changed. Run the commands above yourself, then run this command again.',
        ])."\n",
        'stderr' => '',
        'answered' => 3,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction'],
        'processes' => [],
        'root' => [
            'resources/js/app.js' => '7f6af59b1b38d2d0a75da1b2d7181661',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => 'fe16dced34caf20ea1996be0811005aa',
        ],
        'component' => false,
    ]);
});

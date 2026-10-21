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

it('installs after a yes in the root, then creates the component', function () {
    ($this->bind)(readyFrontendRoot(['package.json']));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Inertia 3 and Vue are required to generate Vue components. To install them:',
            '  npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components',
            '',
            '  Inertia 3 and Vue are not installed in this project. Install them with the commands above? (yes/no) [yes]',
            '❯ ',
            '   INFO  Installing Inertia 3 and Vue...  ',
            '',
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 4,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction', 'resolve:CheckViteConfigurationAction'],
        'processes' => [
            [
                ['npm', 'install', 'vue@^3.5', '@inertiajs/vue3@^3.0', '@vitejs/plugin-vue', 'unplugin-vue-components'],
                '<root>',
            ],
        ],
        'root' => [
            'resources/js/app.js' => '7f6af59b1b38d2d0a75da1b2d7181661',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => 'fe16dced34caf20ea1996be0811005aa',
        ],
        'component' => true,
    ]);
});

it('installs the server package alone when it is the missing one', function () {
    ($this->bind)(readyFrontendRoot(), null);
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Inertia 3 and Vue are required to generate Vue components. To install them:',
            '  composer require inertiajs/inertia-laravel:^3.0',
            '',
            '  Inertia 3 and Vue are not installed in this project. Install them with the commands above? (yes/no) [yes]',
            '❯ ',
            '   INFO  Installing Inertia 3 and Vue...  ',
            '',
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 4,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction', 'resolve:CheckViteConfigurationAction'],
        'processes' => [[['composer', 'require', 'inertiajs/inertia-laravel:^3.0'], '<root>']],
        'root' => [
            'package.json' => '0f32e3300b4fdbdfe4e94f38a68ec8d0',
            'resources/js/app.js' => '7f6af59b1b38d2d0a75da1b2d7181661',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => 'fe16dced34caf20ea1996be0811005aa',
        ],
        'component' => true,
    ]);
});

it('reports a Node check that fails and runs nothing', function () {
    ($this->bind)(readyFrontendRoot(['package.json']), '3.0.1', fn () => throw new RuntimeException('Node 20 or newer is required.'));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Inertia 3 and Vue are required to generate Vue components. To install them:',
            '  npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components',
            '',
            '  Inertia 3 and Vue are not installed in this project. Install them with the commands above? (yes/no) [yes]',
            '❯ ',
            '   INFO  Installing Inertia 3 and Vue...  ',
            '',
            'Node 20 or newer is required.',
        ])."\n",
        'stderr' => '',
        'answered' => 4,
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

it('reports a failing install and creates nothing', function () {
    ($this->bind)(readyFrontendRoot(['package.json']));
    $this->installer->fails = true;
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Inertia 3 and Vue are required to generate Vue components. To install them:',
            '  npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components',
            '',
            '  Inertia 3 and Vue are not installed in this project. Install them with the commands above? (yes/no) [yes]',
            '❯ ',
            '   INFO  Installing Inertia 3 and Vue...  ',
            '',
            'Failed to install Inertia and Vue: failed',
        ])."\n",
        'stderr' => '',
        'answered' => 4,
        'resolved' => ['resolve:CheckInertiaVueAction', 'resolve:InstallInertiaVueAction'],
        'processes' => [
            [
                ['npm', 'install', 'vue@^3.5', '@inertiajs/vue3@^3.0', '@vitejs/plugin-vue', 'unplugin-vue-components'],
                '<root>',
            ],
        ],
        'root' => [
            'resources/js/app.js' => '7f6af59b1b38d2d0a75da1b2d7181661',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => 'fe16dced34caf20ea1996be0811005aa',
        ],
        'component' => false,
    ]);
});

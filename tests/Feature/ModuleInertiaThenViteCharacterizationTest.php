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

it('asks the Inertia question, then the Vite question', function () {
    ($this->bind)(installerRoot(['package.json' => json_encode([])]));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes', 'yes'], true);

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
        'answered' => 8,
        'resolved' => [
            'resolve:CheckInertiaVueAction',
            'resolve:InstallInertiaVueAction',
            'resolve:CheckViteConfigurationAction',
            'resolve:ScaffoldInertiaFrontendAction',
        ],
        'processes' => [
            [
                ['npm', 'install', 'vue@^3.5', '@inertiajs/vue3@^3.0', '@vitejs/plugin-vue', 'unplugin-vue-components'],
                '<root>',
            ],
        ],
        'root' => [
            'package.json' => 'd751713988987e9331980363e24189ce',
            'resources/js/app.js' => '540cebd0557ee78301c603a78a517037',
            'resources/views/app.blade.php' => '05c43d0337a5e506fe0ba151a580c2be',
            'vite.config.js' => '2e6c6006ceacede252c625f80ae7f34f',
        ],
        'component' => true,
    ]);
});

it('stops at a declined Inertia question before the Vite check', function () {
    ($this->bind)(installerRoot(['package.json' => json_encode([])]));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['no', 'yes'], true);

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
            'package.json' => 'd751713988987e9331980363e24189ce',
        ],
        'component' => false,
    ]);
});

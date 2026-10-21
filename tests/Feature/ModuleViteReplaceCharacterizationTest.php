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

it('prints the notice, then writes only the missing root view after a yes', function () {
    ($this->bind)(installerRoot([
        'package.json' => json_encode(['dependencies' => ['vue' => '^3.5.0', '@inertiajs/vue3' => '^3.0.0']]),
        'vite.config.js' => "import vue from '@vitejs/plugin-vue';",
        'resources/js/app.js' => 'createInertiaApp({});',
    ]));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Notice: Existing frontend configuration detected. Nothing was changed in it. To enable modular autodiscovery for your setup, verify the following:',
            '1. In resources/js/app.(ts|js), update the createInertiaApp resolve callback:',
            '   resolve: (name) => {',
            '       if (name.includes(\'::\')) {',
            '           const [module, page] = name.split(\'::\');',
            '           return resolvePageComponent(',
            '               `../../modules/${module}/resources/js/Pages/${page}.vue`,',
            '               import.meta.glob(\'../../modules/**/resources/js/Pages/**/*.vue\')',
            '           );',
            '       }',
            '       return resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob(\'./Pages/**/*.vue\'));',
            '   },',
            '2. In vite.config.(ts|js), configure unplugin-vue-components and module refresh:',
            '   Components({ dirs: [\'resources/js/Components\', \'modules/*/resources/js/Components\'], dts: true }),',
            '   laravel({ input: \'resources/js/app.js\', refresh: [\'resources/**\', \'modules/**/resources/**\'] }),',
            'The Vite and Inertia root files need these changes:',
            'create resources/views/app.blade.php',
            '<contents 201b40fc>',
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
            'resources/js/app.js' => '9177fb0f98b07ecc23c965e40416bbba',
            'resources/views/app.blade.php' => '05c43d0337a5e506fe0ba151a580c2be',
            'vite.config.js' => '6f138d76f43c0868b4451f978796f1d5',
        ],
        'component' => true,
    ]);
});

it('says replace for an existing config that can be scaffolded', function () {
    ($this->bind)(installerRoot([
        'package.json' => json_encode(['dependencies' => ['vue' => '^3.5.0', '@inertiajs/vue3' => '^3.0.0']]),
        'vite.config.js' => 'export default {};',
        'resources/js/app.js' => 'modules/**/resources/js/Pages',
        'resources/views/app.blade.php' => '<html></html>',
    ]));
    $result = ($this->drive)('module:vue', ['name' => 'PostCard', '--module' => 'Blog'], [], false);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'The Vite and Inertia root files need these changes:',
            'replace vite.config.js',
            '<contents 3127d1ce>',
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
            'resources/js/app.js' => '8ea6b86712d9730437126cc6fe4babb8',
            'resources/views/app.blade.php' => 'c83301425b2ad1d496473a5ff3d9ecca',
            'vite.config.js' => 'f641467ba7c3788e9020a218802fc0cf',
        ],
        'component' => true,
    ]);
});

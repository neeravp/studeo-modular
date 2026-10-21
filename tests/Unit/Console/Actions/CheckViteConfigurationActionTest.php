<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Actions\CheckViteConfigurationAction;
use Studeo\Modular\DataObjects\ViteStatus;
use Studeo\Modular\Enums\FrontendFileState;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

function viteCheck(string $root): ViteStatus
{
    return (new CheckViteConfigurationAction(app(Filesystem::class), $root))->execute();
}

afterEach(fn () => cleanInstallerRoots());

it('plans to create all three files in an empty project', function () {
    $status = viteCheck(installerRoot());

    expect($status->isConfigured())->toBeFalse()
        ->and($status->appJs)->toBe(FrontendFileState::Scaffoldable)
        ->and($status->viteConfig)->toBe(FrontendFileState::Scaffoldable)
        ->and($status->rootViewExists)->toBeFalse()
        ->and($status->pendingWrites())->toBe([
            'resources/views/app.blade.php' => 'create',
            'vite.config.js' => 'create',
            'resources/js/app.js' => 'create',
        ])
        ->and($status->customFiles())->toBe([]);
});

it('plans TypeScript file names for a TypeScript project', function () {
    $status = viteCheck(installerRoot(['tsconfig.json' => '{}']));

    expect(array_keys($status->pendingWrites()))->toBe(['resources/views/app.blade.php', 'vite.config.ts', 'resources/js/app.ts']);
});

it('is configured when the page resolver, component discovery and root view exist', function () {
    $status = viteCheck(readyFrontendRoot());

    expect($status->isConfigured())->toBeTrue()
        ->and($status->pendingWrites())->toBe([])
        ->and($status->customFiles())->toBe([]);
});

it('replaces a default app.js and a default vite config, and says so', function () {
    $root = installerRoot([
        'vite.config.js' => 'export default {}',
        'resources/js/app.js' => "import './bootstrap';",
        'resources/views/app.blade.php' => '<html></html>',
    ]);

    expect(viteCheck($root)->pendingWrites())->toBe(['vite.config.js' => 'replace', 'resources/js/app.js' => 'replace']);
});

it('never plans to write a custom app.js or vite config', function () {
    $root = installerRoot([
        'vite.config.js' => "import vue from '@vitejs/plugin-vue';",
        'resources/js/app.js' => 'createInertiaApp({ resolve: () => null });',
    ]);
    $status = viteCheck($root);

    expect($status->appJs)->toBe(FrontendFileState::Custom)
        ->and($status->viteConfig)->toBe(FrontendFileState::Custom)
        ->and(array_keys($status->pendingWrites()))->toBe(['resources/views/app.blade.php'])
        ->and($status->customFiles())->toBe(['vite.config.js', 'resources/js/app.js']);
});

it('writes nothing while inspecting', function () {
    $root = installerRoot(['vite.config.js' => 'export default {}']);
    $before = frontendSnapshot($root);

    viteCheck($root);

    expect(frontendSnapshot($root))->toBe($before);
});

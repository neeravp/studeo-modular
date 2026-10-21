<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Actions\CheckViteConfigurationAction;
use Studeo\Modular\Console\Actions\ScaffoldInertiaFrontendAction;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

afterEach(fn () => cleanInstallerRoots());

function scaffolder(string $root): array
{
    $files = app(Filesystem::class);

    return [new CheckViteConfigurationAction($files, $root), new ScaffoldInertiaFrontendAction($files, new StubRenderer, new ArtifactWriter($files), $root)];
}

it('renders the planned files without writing them', function () {
    $root = installerRoot();
    [$check, $scaffold] = scaffolder($root);

    $plan = $scaffold->plan($check->execute());

    expect(array_keys($plan))->toBe(['resources/views/app.blade.php', 'vite.config.js', 'resources/js/app.js'])
        ->and($plan['vite.config.js'])->toContain('modules/*/resources/js/Components')
        ->and($plan['resources/js/app.js'])->toContain('modules/**/resources/js/Pages')
        ->and(frontendSnapshot($root))->toBe([]);
});

it('writes exactly the planned files', function () {
    $root = installerRoot();
    [$check, $scaffold] = scaffolder($root);

    $written = $scaffold->execute($check->execute());

    expect($written)->toBe(['resources/views/app.blade.php', 'vite.config.js', 'resources/js/app.js'])
        ->and(array_keys(frontendSnapshot($root)))->toHaveCount(3)
        ->and((new CheckViteConfigurationAction(app(Filesystem::class), $root))->execute()->isConfigured())->toBeTrue();
});

it('leaves a custom vite config and app.js untouched', function () {
    $root = installerRoot([
        'vite.config.js' => "import vue from '@vitejs/plugin-vue';",
        'resources/js/app.js' => 'createInertiaApp({});',
    ]);
    [$check, $scaffold] = scaffolder($root);
    $before = frontendSnapshot($root);

    $written = $scaffold->execute($check->execute());

    expect($written)->toBe(['resources/views/app.blade.php'])
        ->and(file_get_contents($root.'/vite.config.js'))->toBe("import vue from '@vitejs/plugin-vue';")
        ->and(file_get_contents($root.'/resources/js/app.js'))->toBe('createInertiaApp({});');
});

it('writes nothing for a configured project', function () {
    $root = readyFrontendRoot();
    [$check, $scaffold] = scaffolder($root);
    $before = frontendSnapshot($root);

    expect($scaffold->execute($check->execute()))->toBe([])
        ->and(frontendSnapshot($root))->toBe($before);
});

<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CheckInertiaVueAction;
use Studeo\Modular\Console\Actions\CheckLivewireAction;
use Studeo\Modular\Console\Actions\CheckViteConfigurationAction;
use Studeo\Modular\Console\Actions\InstallInertiaVueAction;
use Studeo\Modular\Console\Actions\ScaffoldInertiaFrontendAction;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\StubRenderer;

/**
 * A fresh root app directory outside the repository, standing in for the project the
 * installers inspect and may write to. Pass files as relative path => contents.
 *
 * @param  array<string, string>  $files
 */
function installerRoot(array $files = []): string
{
    $root = sys_get_temp_dir().'/modular-root-'.bin2hex(random_bytes(6));
    $GLOBALS['installerRoots'] ??= [];
    $GLOBALS['installerRoots'][] = $root;

    if (count($GLOBALS['installerRoots']) === 1) {
        register_shutdown_function('cleanInstallerRoots');
    }

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        File::put($root.'/'.$path, $contents);
    }

    File::ensureDirectoryExists($root);

    return $root;
}

/**
 * Removes every directory installerRoot() made.
 */
function cleanInstallerRoots(): void
{
    foreach ($GLOBALS['installerRoots'] ?? [] as $root) {
        File::deleteDirectory($root);
    }

    $GLOBALS['installerRoots'] = [];
}

/**
 * A root app with Inertia 3, Vue and the modular Vite setup all in place, minus the named files.
 *
 * @param  array<int, string>  $without
 */
function readyFrontendRoot(array $without = []): string
{
    return installerRoot(array_diff_key([
        'package.json' => json_encode(['dependencies' => ['vue' => '^3.5.0', '@inertiajs/vue3' => '^3.0.0']]),
        'vite.config.js' => "Components({ dirs: ['modules/*/resources/js/Components'] })",
        'resources/js/app.js' => "import.meta.glob('../../modules/**/resources/js/Pages/**/*.vue')",
        'resources/views/app.blade.php' => '<html></html>',
    ], array_flip($without)));
}

/**
 * The testbench skeleton's own frontend files, which no installer test may touch.
 *
 * @return array<string, string>
 */
function skeletonFrontendSnapshot(): array
{
    $snapshot = [];

    foreach (['vite.config.js', 'package.json', 'composer.json'] as $file) {
        $snapshot[$file] = is_file(base_path($file)) ? md5_file(base_path($file)) : 'absent';
    }

    foreach (File::exists(base_path('resources')) ? File::allFiles(base_path('resources')) : [] as $file) {
        $snapshot[$file->getPathname()] = md5_file($file->getPathname());
    }

    return $snapshot;
}

/**
 * Every file the installers may touch, hashed, so a refusal can be shown to have written nothing.
 *
 * @return array<string, string>
 */
function frontendSnapshot(string $root): array
{
    $snapshot = [];

    foreach (File::exists($root) ? File::allFiles($root, true) : [] as $file) {
        $snapshot[$file->getPathname()] = md5_file($file->getPathname());
    }

    ksort($snapshot);

    return $snapshot;
}

/**
 * Points the Inertia, Vue and Vite checks and the scaffolder at a root app directory, with the
 * server package reported at the given version. The default is a project with everything ready.
 */
function bindFrontendRoot(?string $root = null, ?string $serverVersion = '3.0.1', ?Closure $nodeValidator = null): string
{
    $root ??= readyFrontendRoot();
    $files = app(Filesystem::class);

    app()->bind(CheckInertiaVueAction::class, fn () => new CheckInertiaVueAction($files, $root, fn (): ?string => $serverVersion));
    app()->bind(CheckViteConfigurationAction::class, fn () => new CheckViteConfigurationAction($files, $root));
    app()->bind(ScaffoldInertiaFrontendAction::class, fn () => new ScaffoldInertiaFrontendAction($files, new StubRenderer, new ArtifactWriter($files), $root));
    app()->bind(InstallInertiaVueAction::class, fn () => new InstallInertiaVueAction($root, $nodeValidator ?? fn () => null));

    return $root;
}

/**
 * Lets `module:vue` run without Inertia and Vue installed, as the Vue command tests do.
 */
function fakeInertiaVueInstalled(): void
{
    bindFrontendRoot();
}

/**
 * Lets `module:livewire` run whether or not Livewire is installed.
 */
function fakeLivewireInstalled(bool $installed = true): void
{
    app()->bind(CheckLivewireAction::class, fn () => new CheckLivewireAction(fn (): ?string => $installed ? '4.0.0' : null));
}

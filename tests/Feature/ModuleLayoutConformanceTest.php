<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Check\ModuleLayout;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    $this->rootAppBefore = $this->rootAppSnapshot();

    $this->artisan('module:make', ['module' => 'Layout'])->assertSuccessful();
    $this->layoutPath = $this->modulesPath.'/Layout';
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);
});

/**
 * Load a generated class in a fresh PHP process and report whether it exists.
 */
function generatedClassLoads(string $file, string $class): bool
{
    $process = new Process([
        PHP_BINARY, '-r',
        'require $argv[1]; require $argv[2]; echo class_exists($argv[3], false) ? "loaded" : "missing";',
        dirname(__DIR__, 2).'/vendor/autoload.php', $file, $class,
    ]);
    $process->run();

    return $process->isSuccessful() && $process->getOutput() === 'loaded';
}

it('writes each generator to its documented directory and namespace', function (string $command, array $arguments, string $relativePath, string $namespace, string $class) {
    $this->artisan($command, ['--module' => 'Layout', ...$arguments])->assertSuccessful();

    $file = $this->layoutPath.'/'.$relativePath;

    expect($file)->toBeFile()
        ->and(File::get($file))->toContain("namespace {$namespace};")
        ->and(generatedClassLoads($file, $class))->toBeTrue()
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
})->with([
    'controller' => ['module:controller', ['name' => 'PublishPostController'], 'Controllers/PublishPostController.php', 'Studeo\\Layout\\Controllers', 'Studeo\\Layout\\Controllers\\PublishPostController'],
    'middleware' => ['module:middleware', ['name' => 'EnsureActive'], 'Middleware/EnsureActive.php', 'Studeo\\Layout\\Middleware', 'Studeo\\Layout\\Middleware\\EnsureActive'],
    'command' => ['module:command', ['name' => 'SendInvoices'], 'Commands/SendInvoices.php', 'Studeo\\Layout\\Commands', 'Studeo\\Layout\\Commands\\SendInvoices'],
    'dto' => ['module:dto', ['name' => 'PostData'], 'Data/PostData.php', 'Studeo\\Layout\\Data', 'Studeo\\Layout\\Data\\PostData'],
    'resource' => ['module:resource', ['name' => 'PostResource'], 'ApiResources/PostResource.php', 'Studeo\\Layout\\ApiResources', 'Studeo\\Layout\\ApiResources\\PostResource'],
]);

it('scaffolds the documented layout and none of the retired directories', function () {
    foreach (['Actions', 'ApiResources', 'Commands', 'Contracts', 'Controllers', 'Data', 'Middleware', 'Models', 'Policies', 'config', 'database/migrations', 'database/factories', 'database/seeders', 'resources/views', 'routes'] as $directory) {
        expect($this->layoutPath.'/'.$directory)->toBeDirectory();
    }

    foreach (['DataObjects', 'Providers', 'Console', 'Http', 'Requests'] as $retired) {
        expect($this->layoutPath.'/'.$retired)->not->toBeDirectory();
    }

    expect($this->layoutPath.'/LayoutServiceProvider.php')->toBeFile()
        ->and($this->layoutPath.'/module.json')->toBeFile();
});

it('scaffolds only directories the shared layout list allows and none it forbids', function () {
    $allowed = [...ModuleLayout::CLASS_DIRECTORIES, ...ModuleLayout::DATA_DIRECTORIES];
    $scaffolded = array_map('basename', File::directories($this->layoutPath));

    expect(array_diff($scaffolded, $allowed))->toBe([])
        ->and(array_intersect(['DataObjects', 'Providers', 'Console', 'Http', 'Requests', 'src'], $allowed))->toBe([]);
});

it('writes nothing outside the module directory when scaffolding a module', function () {
    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

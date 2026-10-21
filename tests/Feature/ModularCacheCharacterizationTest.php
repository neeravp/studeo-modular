<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\BuildModuleCacheAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\ModuleCache;
use Studeo\Modular\Support\RootComposer;
use Studeo\Modular\Tests\Helpers\OrderedFilesystem;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture;
    $this->composerPath = $this->fixture->composerPath;
    $this->rootPath = $this->fixture->rootPath;
    $this->cachePath = $this->moduleCachePath();
    File::delete($this->cachePath);
    $this->psr4 = ['App\\' => 'app/'];
    $this->module = function (string $name, bool $broken = false) {
        app(CreateModuleAction::class)->execute(new ModuleDefinition($name, "Studeo\\{$name}", "{$this->rootPath}/{$name}"));
        $this->psr4["Studeo\\{$name}\\"] = "modules/{$name}/";
        if ($broken) {
            $this->writeRawModuleManifest("{$this->rootPath}/{$name}", '{not json');
        }
        File::put($this->composerPath, json_encode(['name' => 'laravel/laravel', 'autoload' => ['psr-4' => $this->psr4]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    };
    $this->files = new OrderedFilesystem;
    $this->files->modulesRoot = $this->rootPath;
    $this->orderFilesystem = function () {
        app()->instance(Filesystem::class, $this->files);
        app()->forgetInstance(ModuleCache::class);
    };
    $this->driver = $this->fixture->driver([RootComposer::class, BuildModuleCacheAction::class, ModuleCache::class]);
    $this->warn = "\n   WARN  Cached entries are not checked against the module files. After adding, removing or renaming a listener, policy or middleware in a cached module, run [modular:clear] (or [modular:cache] again).  \n\n";
    $this->info = fn (int $count): string => "\n   INFO  Cached listeners, policies and middleware for {$count} module(s).  \n";
    $this->closing = "   ERROR  Modules failed to load, so nothing was cached. Fix them and run [modular:cache] again.  \n\n";
    $this->outcome = fn (int $status, string $stdout, array $resolved): array => [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => '',
        'answered' => 0,
        'resolved' => $resolved,
    ];
});

afterEach(function () {
    File::delete($this->cachePath);
    $this->fixture->tearDown();
});

it('writes the cache and says how many modules it cached, then warns that it is not revalidated', function () {
    ($this->module)('Account');
    ($this->module)('Billing');
    $resolved = ['resolve:RootComposer', 'resolve:ResolveModuleAction', 'resolve:ModuleCache', 'resolve:BuildModuleCacheAction'];

    expect($this->driver->run('modular:cache', []))->toBe(($this->outcome)(0, ($this->info)(2).$this->warn, $resolved))
        ->and(array_keys((include $this->cachePath)['modules']))->toBe(['account', 'billing']);
});

it('counts one module and no module', function () {
    ($this->module)('Account');

    expect($this->driver->run('modular:cache', [])['stdout'])->toBe(($this->info)(1).$this->warn);

    File::put($this->composerPath, json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    expect($this->driver->run('modular:cache', [])['stdout'])->toBe(($this->info)(0).$this->warn)
        ->and((include $this->cachePath)['modules'])->toBe([]);
});

it('resolves the cache while it builds the builder, and the builder last', function () {
    $resolved = ['resolve:RootComposer', 'resolve:ResolveModuleAction', 'resolve:ModuleCache', 'resolve:BuildModuleCacheAction'];
    app()->forgetInstance(ModuleCache::class);

    expect($this->driver->run('modular:cache', [])['resolved'])->toBe($resolved);
});

it('prints each failure in module order, then the closing line, exits 1 and writes nothing', function () {
    ($this->module)('Account');
    ($this->module)('Alpha', broken: true);
    ($this->module)('Beta', broken: true);
    $result = $this->driver->run('modular:cache', []);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toBe('')
        ->and($result['stdout'])->toBe(
            "\n   ERROR  Module [Alpha] failed to load: Invalid module manifest modules/Alpha/module.json: Syntax error.  \n"
            ."\n   ERROR  Module [Beta] failed to load: Invalid module manifest modules/Beta/module.json: Syntax error.  \n"
            ."\n".$this->closing
        )
        ->and($this->cachePath)->not->toBeFile();
});

it('keeps an existing cache byte for byte when a module fails', function () {
    ($this->module)('Account');
    $this->driver->run('modular:cache', []);
    $existing = File::get($this->cachePath);
    ($this->module)('Broken', broken: true);
    $result = $this->driver->run('modular:cache', []);

    expect($result['status'])->toBe(1)
        ->and(File::get($this->cachePath))->toBe($existing)
        ->and(array_values(array_filter(scandir(dirname($this->cachePath)), fn (string $name): bool => str_starts_with($name, 'modular'))))->toBe([basename($this->cachePath)]);
});

it('reads the modules and composer.json first, and only then touches the cache directory', function () {
    ($this->module)('Account');
    ($this->orderFilesystem)();
    $this->driver->run('modular:cache', []);
    $calls = $this->files->calls;
    $directory = array_search('ensureDirectoryExists <package>/vendor/orchestra/testbench-core/laravel/bootstrap/cache', $calls, true);

    expect($calls)->not->toBe([])
        ->and($directory)->toBeInt()
        ->and(array_slice($calls, $directory))->toBe(['ensureDirectoryExists <package>/vendor/orchestra/testbench-core/laravel/bootstrap/cache'])
        ->and(array_slice($calls, 0, 1))->toBe(['isFile <package>/vendor/orchestra/testbench-core/laravel/composer.json true']);
});

it('never reaches the cache directory when a module fails', function () {
    ($this->module)('Broken', broken: true);
    ($this->orderFilesystem)();
    $this->driver->run('modular:cache', []);

    expect($this->files->calls)->not->toBe([])
        ->and(array_filter($this->files->calls, fn (string $call): bool => str_contains($call, 'ensureDirectoryExists') || str_contains($call, 'put ')))->toBe([]);
});

<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->originalRootComposer = File::get($this->app->basePath('composer.json'));

    $modulePath = config('modular.root_path');
    File::deleteDirectory($modulePath);
    File::ensureDirectoryExists($modulePath);
});

afterEach(function () {
    File::put($this->app->basePath('composer.json'), $this->originalRootComposer);

    $modulePath = config('modular.root_path');
    File::deleteDirectory($modulePath);
});

it('creates a module with the default scaffold', function () {
    $this->artisan('module:make', [
        'module' => 'Account',
    ])->assertSuccessful();

    $modulePath = $this->app->basePath('modules/Account');

    expect($modulePath)->toBeDirectory();

    expect([
        'Actions',
        'ApiResources',
        'Commands',
        'Contracts',
        'Controllers',
        'Data',
        'Middleware',
        'Models',
        'Policies',
        'database',
        'database/factories',
        'database/migrations',
        'database/seeders',
        'resources',
        'resources/css',
        'resources/js',
        'resources/views',
        'routes',
        'config',
    ])->each(
        fn ($path) => $path
            ->and($modulePath.'/'.$path->value)
            ->toBeDirectory()
    );

    expect([
        'AccountServiceProvider.php',
        'module.json',
        'config/account.php',
        'resources/css/module.css',
        'resources/js/module.js',
        'routes/web.php',
    ])->each(fn ($path) => $path
        ->and($modulePath.'/'.$path->value)
        ->toBeFile()
    );
});

it('generates the module service provider', function () {
    $this->artisan('module:make', [
        'module' => 'Account',
    ])->assertSuccessful();

    $provider = File::get(
        $this->app->basePath('modules/Account/AccountServiceProvider.php')
    );

    expect($provider)
        ->toContain('namespace Studeo\\Account;')
        ->toContain('class AccountServiceProvider extends ServiceProvider')
        ->toContain('use Studeo\\Modular\\Support\\ModuleRegistrar;')
        ->toContain('ModuleRegistrar::for($this, __DIR__)')
        ->not->toContain('mergeConfigFrom(')
        ->not->toContain('loadRoutesFrom(');
});

it('names the config file after the kebab-case module key', function () {
    $this->artisan('module:make', [
        'module' => 'BlogPosts',
    ])->assertSuccessful();

    expect($this->app->basePath('modules/BlogPosts/config/blog-posts.php'))->toBeFile()
        ->and($this->app->basePath('modules/BlogPosts/config/blogposts.php'))->not->toBeFile();
});

it('generates the module manifest', function () {
    $this->artisan('module:make', [
        'module' => 'Account',
    ])->assertSuccessful();

    $manifest = json_decode(
        File::get($this->app->basePath('modules/Account/module.json')),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($manifest)->toBe([
        'name' => 'Account',
        'namespace' => 'Studeo\\Account',
    ]);
});

it('generates module routes with the web middleware group', function () {
    $this->artisan('module:make', [
        'module' => 'Account',
    ])->assertSuccessful();

    $routes = File::get(
        $this->app->basePath('modules/Account/routes/web.php')
    );

    expect($routes)
        ->toContain('use Illuminate\\Support\\Facades\\Route;')
        ->toContain("Route::middleware('web')->group(function () {");
});

it('does not modify the root application composer file', function () {
    $composerPath = $this->app->basePath('composer.json');

    File::put($composerPath, json_encode([
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $before = File::get($composerPath);

    $this->artisan('module:make', [
        'module' => 'Account',
    ])->assertSuccessful();

    expect(File::get($composerPath))->toBe($before);
});

it('does not create a module in the root composer psr-4 mapping', function () {
    $composerPath = $this->app->basePath('composer.json');

    File::put($composerPath, json_encode([
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->artisan('module:make', [
        'module' => 'Account',
    ])->assertSuccessful();

    $composer = json_decode(
        File::get($composerPath),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($composer['autoload']['psr-4'])->toBe([
        'App\\' => 'app/',
    ]);
});

it('refuses to create a module that already exists', function () {
    $modulePath = $this->app->basePath('modules/Account');

    File::ensureDirectoryExists($modulePath);
    File::put($modulePath.'/existing.txt', 'do not overwrite me');

    $this->artisan('module:make', [
        'module' => 'Account',
    ])->assertFailed();

    expect(File::get($modulePath.'/existing.txt'))
        ->toBe('do not overwrite me');
});

it('makes a module with no namespace prefix when root_namespace is null', function () {
    config()->set('modular.root_namespace', null);

    $this->artisan('module:make', [
        'module' => 'Account',
    ])->assertSuccessful();

    $manifest = json_decode(
        File::get($this->app->basePath('modules/Account/module.json')),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($manifest)->toBe([
        'name' => 'Account',
        'namespace' => 'Account',
    ]);

    $provider = File::get(
        $this->app->basePath('modules/Account/AccountServiceProvider.php')
    );

    expect($provider)
        ->toContain('namespace Account;')
        ->toContain('class AccountServiceProvider');
});

it('prompts for module name when argument is not provided', function () {
    $this->artisan('module:make')
        ->expectsQuestion('What is the name of the module?', 'Account')
        ->expectsOutput('Module [Account] created successfully.')
        ->assertSuccessful();

    expect($this->app->basePath('modules/Account'))->toBeDirectory();
});

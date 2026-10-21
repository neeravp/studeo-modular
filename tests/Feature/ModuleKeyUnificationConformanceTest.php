<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\DiscoverInstalledModulesAction;
use Studeo\Modular\Console\Actions\DiscoverLivewireComponentsAction;
use Studeo\Modular\Console\Actions\DiscoverVueComponentsAction;
use Studeo\Modular\Providers\ModularServiceProvider;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->livewire = $this->fakeLivewire();

    $this->rootComposerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->rootComposerPath) ? File::get($this->rootComposerPath) : null;
    File::put($this->rootComposerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    fakeInertiaVueInstalled();

    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

it('uses one kebab-case key for a multi-word module across config, views, Livewire and Vue', function () {
    $this->artisan('module:make', ['module' => 'BlogPosts'])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'Counter', '--module' => 'BlogPosts'])->assertSuccessful();
    $this->artisan('module:vue', ['name' => 'Badge', '--module' => 'BlogPosts'])->assertSuccessful();
    $this->artisan('module:install', ['module' => 'BlogPosts'])->assertSuccessful();

    $module = app(DiscoverInstalledModulesAction::class)->execute()['BlogPosts']->module;

    expect($this->modulesPath.'/BlogPosts/config/blog-posts.php')->toBeFile()
        ->and(File::get($this->modulesPath.'/BlogPosts/Livewire/Counter.php'))->toContain("view('blog-posts::livewire.counter')")
        ->and(array_map(fn ($component) => $component->aliases(), array_values(app(DiscoverLivewireComponentsAction::class)->forModule($module))))
        ->toBe([['blog-posts.counter', 'blog-posts::counter']])
        ->and(array_keys(app(DiscoverVueComponentsAction::class)->execute()))
        ->each->toStartWith('blog-posts::')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('registers the generated Livewire component under the kebab key when the provider boots, and loads the provider in a subprocess', function () {
    $this->artisan('module:make', ['module' => 'BlogPosts'])->assertSuccessful();
    $this->autoloadModule('Studeo\\BlogPosts', $this->modulesPath.'/BlogPosts');
    $this->artisan('module:livewire', ['name' => 'Counter', '--module' => 'BlogPosts'])->assertSuccessful();
    $this->artisan('module:install', ['module' => 'BlogPosts'])->assertSuccessful();

    (new ModularServiceProvider($this->app))->register();

    $process = new Process([
        PHP_BINARY, '-r',
        'require $argv[1]; require $argv[2]; echo class_exists($argv[3], false) ? "loaded" : "missing";',
        dirname(__DIR__, 2).'/vendor/autoload.php',
        $this->modulesPath.'/BlogPosts/BlogPostsServiceProvider.php',
        'Studeo\\BlogPosts\\BlogPostsServiceProvider',
    ]);
    $process->run();

    expect($this->livewire->registered)->toBe(['blog-posts.counter' => 'Studeo\\BlogPosts\\Livewire\\Counter'])
        ->and(array_keys($this->livewire->namespaces))->toBe(['blog-posts'])->and($process->getOutput())->toBe('loaded')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

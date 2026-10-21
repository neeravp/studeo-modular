<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\EnsureProviderUsesRegistrarAction;
use Studeo\Modular\Console\Actions\RenderModuleProviderAction;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Exceptions\LegacyModuleProviderException;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->directory = config('modular.root_path').'/BlogPosts';
    File::deleteDirectory(config('modular.root_path'));
    File::ensureDirectoryExists($this->directory);

    $this->manifest = new ModuleManifest('BlogPosts', 'Studeo\\BlogPosts');
    $this->providerFile = $this->directory.'/BlogPostsServiceProvider.php';
});

afterEach(function () {
    File::deleteDirectory(config('modular.root_path'));
});

it('renders the replacement provider with the module namespace and class name', function () {
    $provider = app(RenderModuleProviderAction::class)->execute($this->manifest);

    expect($provider)
        ->toContain('namespace Studeo\\BlogPosts;')
        ->toContain('class BlogPostsServiceProvider extends ServiceProvider')
        ->toContain('ModuleRegistrar::for($this, __DIR__)->config();')
        ->not->toContain('{moduleName}')
        ->not->toContain('{moduleNamespace}');
});

it('accepts a provider that references ModuleRegistrar', function () {
    File::put($this->providerFile, app(RenderModuleProviderAction::class)->execute($this->manifest));

    app(EnsureProviderUsesRegistrarAction::class)->execute($this->manifest, $this->providerFile);

    expect(true)->toBeTrue();
});

it('accepts a customised provider as long as it references ModuleRegistrar', function () {
    File::put($this->providerFile, "<?php\n\nuse Studeo\\Modular\\Support\\ModuleRegistrar;\n// custom\nclass X { function register() { ModuleRegistrar::for(\$this, __DIR__)->config(); } }\n");

    app(EnsureProviderUsesRegistrarAction::class)->execute($this->manifest, $this->providerFile);

    expect(true)->toBeTrue();
});

it('rejects a provider that does not reference ModuleRegistrar and prints the replacement and the key note', function () {
    File::put($this->providerFile, "<?php\n\nclass BlogPostsServiceProvider extends \\Illuminate\\Support\\ServiceProvider\n{\n    public function boot(): void\n    {\n        \$this->loadViewsFrom(__DIR__.'/resources/views', 'blogposts');\n    }\n}\n");

    try {
        app(EnsureProviderUsesRegistrarAction::class)->execute($this->manifest, $this->providerFile);
        $this->fail('LegacyModuleProviderException was not thrown');
    } catch (LegacyModuleProviderException $exception) {
        expect($exception->getMessage())
            ->toContain($this->providerFile)
            ->toContain('does not use ModuleRegistrar')
            ->toContain(app(RenderModuleProviderAction::class)->execute($this->manifest))
            ->toContain('kebab case')
            ->toContain('config/blogposts.php becomes config/blog-posts.php')
            ->toContain('blog-posts::')
            ->toContain('Livewire')
            ->toContain('Vue');
    }
});

it('uses a generic example in the key note for a single-word module', function () {
    $manifest = new ModuleManifest('Account', 'Studeo\\Account');
    $providerFile = config('modular.root_path').'/Account/AccountServiceProvider.php';
    File::ensureDirectoryExists(dirname($providerFile));
    File::put($providerFile, "<?php\nclass AccountServiceProvider {}\n");

    try {
        app(EnsureProviderUsesRegistrarAction::class)->execute($manifest, $providerFile);
        $this->fail('LegacyModuleProviderException was not thrown');
    } catch (LegacyModuleProviderException $exception) {
        expect($exception->getMessage())->toContain('config/blogposts.php becomes config/blog-posts.php');
    }
});

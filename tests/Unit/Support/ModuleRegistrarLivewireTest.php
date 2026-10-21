<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Support\ModuleLoadFailures;
use Studeo\Modular\Support\ModuleRegistrar;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->livewire = $this->fakeLivewire();

    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->modulesPath = $this->fixture->rootPath;
});

afterEach(fn () => $this->fixture->tearDown());

function livewireModule(object $test, string $name, string $namespace): string
{
    $directory = $test->modulesPath.'/'.$name;
    $test->writeModuleManifest($directory, $name, $namespace);
    $test->autoloadModule($namespace, $directory);

    foreach ([
        'Livewire/Counter.php' => "namespace {$namespace}\\Livewire;\nuse Livewire\\Component;\nclass Counter extends Component {}",
        'Livewire/Admin/UserTable.php' => "namespace {$namespace}\\Livewire\\Admin;\nuse Livewire\\Component;\nclass UserTable extends Component {}",
        'Livewire/Broken.php' => "namespace {$namespace}\\Livewire;\nuse Livewire\\Component;\nclass Broken extends Component {}",
        'Livewire/⚡quick.blade.php' => null,
        'Livewire/⚡gauge/gauge.php' => null,
        'Livewire/⚡gauge/gauge.blade.php' => null,
        'Middleware/EnsureActive.php' => "namespace {$namespace}\\Middleware;\nclass EnsureActive {}",
    ] as $relative => $contents) {
        File::ensureDirectoryExists(dirname($directory.'/'.$relative));
        File::put($directory.'/'.$relative, $contents === null ? viewBased($relative) : "<?php\n\n{$contents}\n");
    }

    return $directory;
}

function viewBased(string $relative): string
{
    return match (true) {
        str_ends_with($relative, '.blade.php') && str_contains($relative, 'gauge') => "<div>\n</div>\n",
        str_ends_with($relative, '.blade.php') => "<?php\n\nuse Livewire\\Component;\n\nnew class extends Component\n{\n};\n?>\n\n<div>\n</div>\n",
        default => "<?php\n\nuse Livewire\\Component;\n\nnew class extends Component\n{\n};\n",
    };
}

function livewireRegistrar(string $directory): ModuleRegistrar
{
    return ModuleRegistrar::for(new class(app()) extends ServiceProvider {}, $directory);
}

it('registers each component under its dot alias, by class or by view path, and one namespace for the :: form', function () {
    $directory = livewireModule($this, 'BlogPosts', 'Studeo\\BlogPosts');
    livewireRegistrar($directory)->livewire();

    $registered = $this->livewire->registered;
    ksort($registered);

    expect($registered)->toBe([
        'blog-posts.admin.user-table' => 'Studeo\\BlogPosts\\Livewire\\Admin\\UserTable',
        'blog-posts.broken' => 'Studeo\\BlogPosts\\Livewire\\Broken',
        'blog-posts.counter' => 'Studeo\\BlogPosts\\Livewire\\Counter',
        'blog-posts.gauge' => realpath($directory.'/Livewire/⚡gauge'),
        'blog-posts.quick' => realpath($directory.'/Livewire/⚡quick.blade.php'),
    ])->and($this->livewire->namespaces)->toBe([
        'blog-posts' => ['viewPath' => $directory.'/Livewire', 'classNamespace' => 'Studeo\\BlogPosts\\Livewire'],
    ])->and(implode(' ', array_keys($registered)))->not->toContain('⚡')->not->toContain('::')->not->toContain('index');
});

it('registers nothing for a module without a Livewire directory', function () {
    $directory = $this->modulesPath.'/Plain';
    $this->writeModuleManifest($directory, 'Plain', 'Studeo\\Plain');

    livewireRegistrar($directory)->livewire();

    expect($this->livewire->registered)->toBe([])
        ->and(app(ModuleLoadFailures::class)->all())->toBe([]);
});

it('records a Livewire registration failure and still registers the other components and steps', function () {
    $this->livewire->failing = ['blog-posts.broken'];

    livewireRegistrar(livewireModule($this, 'BlogPosts', 'Studeo\\BlogPosts'))->livewire()->middleware();

    $failures = app(ModuleLoadFailures::class)->all();

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toBeInstanceOf(ModuleLoadException::class)
        ->and($failures[0]->getMessage())->toContain('Module [BlogPosts] failed to load')->toContain('blog-posts.broken')
        ->and($this->livewire->registered)->toHaveKeys(['blog-posts.counter', 'blog-posts.quick', 'blog-posts.gauge'])
        ->and($this->livewire->registered)->not->toHaveKey('blog-posts.broken')
        ->and($this->livewire->namespaces)->toHaveKey('blog-posts')
        ->and(app('router')->getMiddleware())->toHaveKey('blog-posts.ensure-active');
});

it('keeps a failing module from affecting another module', function () {
    $this->livewire->failing = ['blog-posts.counter'];

    livewireRegistrar(livewireModule($this, 'BlogPosts', 'Studeo\\BlogPosts'))->livewire();
    livewireRegistrar(livewireModule($this, 'Shop', 'Studeo\\Shop'))->livewire()->policies();

    expect($this->livewire->registered)->toHaveKeys(['shop.counter', 'shop.admin.user-table'])
        ->and(app(ModuleLoadFailures::class)->all())->toHaveCount(1)
        ->and($this->app->make(Gate::class)->policies())->toBe([]);
});

it('records a failing namespace as one failure and still registers every component', function () {
    $this->livewire->failing = ['blog-posts'];

    livewireRegistrar(livewireModule($this, 'BlogPosts', 'Studeo\\BlogPosts'))->livewire();

    $failures = app(ModuleLoadFailures::class)->all();

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->getMessage())->toContain('Module [BlogPosts] failed to load')->toContain('blog-posts')
        ->and($this->livewire->namespaces)->toBe([])
        ->and($this->livewire->registered)->toHaveKeys(['blog-posts.counter', 'blog-posts.quick', 'blog-posts.gauge', 'blog-posts.broken']);
});

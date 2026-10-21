<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition(
        name: 'Blog',
        namespace: 'Studeo\\Blog',
        path: $this->rootModulesPath.'/Blog',
    );

    app(CreateModuleAction::class)->execute($this->blogModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

test('it creates a full-page component in Pages folder and scaffolds default layout on demand', function () {
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(page: true);
    $result = $action->execute($this->blogModule, 'UserProfile', $options);

    $classPath = $this->blogModule->path.'/Livewire/Pages/UserProfile.php';
    $viewPath = $this->blogModule->path.'/resources/views/livewire/pages/user-profile.blade.php';
    $testPath = $this->blogModule->path.'/tests/Feature/Livewire/Pages/UserProfileTest.php';
    $layoutPath = $this->blogModule->path.'/resources/views/layouts/app.blade.php';

    expect($classPath)->toBeFile()
        ->and($viewPath)->toBeFile()
        ->and($testPath)->toBeFile()
        ->and($layoutPath)->toBeFile()
        ->and($result->component->name)->toBe('Pages/UserProfile')
        ->and($result->component->alias)->toBe('pages.user-profile')
        ->and($result->createdFiles)->toContain($layoutPath)
        ->and($result->createdFiles)->toContain($classPath);

    $classContent = File::get($classPath);
    expect($classContent)
        ->toContain('namespace Studeo\Blog\Livewire\Pages;')
        ->toContain('use Livewire\Attributes\Layout;')
        ->toContain('use Livewire\Attributes\Title;')
        ->toContain("#[Layout('blog::layouts.app')]")
        ->toContain("#[Title('User Profile')]")
        ->toContain("return view('blog::livewire.pages.user-profile');");

    $layoutContent = File::get($layoutPath);
    expect($layoutContent)
        ->toContain('<!DOCTYPE html>')
        ->toContain('{{ $slot }}')
        ->toContain("{{ \$title ?? config('app.name', 'App') }}");
});

test('it does not duplicate Pages prefix if user already specified Pages/', function () {
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(page: true);
    $result = $action->execute($this->blogModule, 'Pages/UserProfile', $options);

    expect($result->component->name)->toBe('Pages/UserProfile')
        ->and($this->blogModule->path.'/Livewire/Pages/UserProfile.php')->toBeFile()
        ->and($this->blogModule->path.'/Livewire/Pages/Pages/UserProfile.php')->not->toBeFile();
});

test('it creates a full-page component with custom layout and scaffolds that layout on demand', function () {
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(page: true, layout: 'admin', title: 'Manage Posts');
    $result = $action->execute($this->blogModule, 'PostList', $options);

    $classPath = $this->blogModule->path.'/Livewire/Pages/PostList.php';
    $layoutPath = $this->blogModule->path.'/resources/views/layouts/admin.blade.php';

    expect($classPath)->toBeFile()
        ->and($layoutPath)->toBeFile();

    $classContent = File::get($classPath);
    expect($classContent)
        ->toContain("#[Layout('blog::layouts.admin')]")
        ->toContain("#[Title('Manage Posts')]");

    $layoutContent = File::get($layoutPath);
    expect($layoutContent)->toContain("{{ \$title ?? config('app.name', 'Admin') }}");
});

test('it does not overwrite existing layout file when creating a page component', function () {
    $action = app(CreateLivewireComponentAction::class);

    $layoutPath = $this->blogModule->path.'/resources/views/layouts/admin.blade.php';
    File::ensureDirectoryExists(dirname($layoutPath));
    File::put($layoutPath, '<!-- Existing Admin Layout -->');

    $options = new LivewireComponentOptions(page: true, layout: 'admin');
    $result = $action->execute($this->blogModule, 'PostList', $options);

    expect(File::get($layoutPath))->toBe('<!-- Existing Admin Layout -->')
        ->and($result->createdFiles)->not->toContain($layoutPath);
});

test('it scaffolds layout in another module when referenced as module.layout', function () {
    $accountsModule = new ModuleDefinition(
        name: 'Accounts',
        namespace: 'Studeo\\Accounts',
        path: $this->rootModulesPath.'/Accounts',
    );
    app(CreateModuleAction::class)->execute($accountsModule);

    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(page: true, layout: 'accounts.admin');
    $result = $action->execute($this->blogModule, 'Profile', $options);

    $accountsLayoutPath = $accountsModule->path.'/resources/views/layouts/admin.blade.php';
    expect($accountsLayoutPath)->toBeFile()
        ->and($result->createdFiles)->toContain($accountsLayoutPath);

    $classContent = File::get($this->blogModule->path.'/Livewire/Pages/Profile.php');
    expect($classContent)->toContain("#[Layout('accounts::layouts.admin')]");
});

test('it scaffolds layout in another module when referenced with module::layout syntax', function () {
    $accountsModule = new ModuleDefinition(
        name: 'Accounts',
        namespace: 'Studeo\\Accounts',
        path: $this->rootModulesPath.'/Accounts',
    );
    app(CreateModuleAction::class)->execute($accountsModule);

    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(page: true, layout: 'accounts::layouts.admin');
    $result = $action->execute($this->blogModule, 'Profile', $options);

    $accountsLayoutPath = $accountsModule->path.'/resources/views/layouts/admin.blade.php';
    expect($accountsLayoutPath)->toBeFile()
        ->and($result->createdFiles)->toContain($accountsLayoutPath);

    $classContent = File::get($this->blogModule->path.'/Livewire/Pages/Profile.php');
    expect($classContent)->toContain("#[Layout('accounts::layouts.admin')]");
});

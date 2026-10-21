<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

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

    // Livewire counts as installed, so no test tries to run composer
    fakeLivewireInstalled();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('creates a classic component by default via artisan command', function () {
    $this->artisan('module:livewire', [
        'name' => 'PostList',
        '--module' => 'Blog',
    ])
        ->expectsOutputToContain('Livewire component [PostList] created successfully in module [Blog].')
        ->expectsOutputToContain('Type: classic')
        ->assertSuccessful();

    $classPath = $this->blogModule->path.'/Livewire/PostList.php';
    $viewPath = $this->blogModule->path.'/resources/views/livewire/post-list.blade.php';
    $testPath = $this->blogModule->path.'/tests/Feature/Livewire/PostListTest.php';

    expect($classPath)->toBeFile()
        ->and($viewPath)->toBeFile()
        ->and($testPath)->toBeFile();
});

it('respects --no-test flag', function () {
    $this->artisan('module:livewire', [
        'name' => 'PostList',
        '--module' => 'Blog',
        '--no-test' => true,
    ])->assertSuccessful();

    $testPath = $this->blogModule->path.'/tests/Feature/Livewire/PostListTest.php';
    expect($testPath)->not->toBeFile();
});

it('creates an MFC component when --mfc flag is passed', function () {
    $this->artisan('module:livewire', [
        'name' => 'PostList',
        '--module' => 'Blog',
        '--mfc' => true,
    ])
        ->expectsOutputToContain('Type: mfc')
        ->assertSuccessful();

    $dir = $this->blogModule->path.'/Livewire/⚡post-list';
    expect($dir.'/post-list.php')->toBeFile()
        ->and($dir.'/post-list.blade.php')->toBeFile()
        ->and($dir.'/post-list.test.php')->toBeFile();
});

it('creates an SFC component when --sfc flag is passed', function () {
    $this->artisan('module:livewire', [
        'name' => 'QuickStatus',
        '--module' => 'Blog',
        '--sfc' => true,
    ])
        ->expectsOutputToContain('Type: sfc')
        ->assertSuccessful();

    $sfcPath = $this->blogModule->path.'/Livewire/⚡quick-status.blade.php';
    expect($sfcPath)->toBeFile();
});

it('creates a full-page component in Pages folder with layout scaffolding', function () {
    $this->artisan('module:livewire', [
        'name' => 'UserProfile',
        '--module' => 'Blog',
        '--page' => true,
        '--layout' => 'admin',
    ])
        ->expectsOutputToContain('Livewire component [Pages/UserProfile] created successfully in module [Blog].')
        ->assertSuccessful();

    $classPath = $this->blogModule->path.'/Livewire/Pages/UserProfile.php';
    $viewPath = $this->blogModule->path.'/resources/views/livewire/pages/user-profile.blade.php';
    $layoutPath = $this->blogModule->path.'/resources/views/layouts/admin.blade.php';

    expect($classPath)->toBeFile()
        ->and($viewPath)->toBeFile()
        ->and($layoutPath)->toBeFile();
});

it('prompts for layout when --page is passed interactively without --layout', function () {
    $this->artisan('module:livewire', [
        'name' => 'Dashboard',
        '--module' => 'Blog',
        '--page' => true,
    ])
        ->expectsQuestion('Which layout should this page component use?', 'admin')
        ->expectsOutputToContain('Livewire component [Pages/Dashboard] created successfully in module [Blog].')
        ->assertSuccessful();

    $classPath = $this->blogModule->path.'/Livewire/Pages/Dashboard.php';
    $layoutPath = $this->blogModule->path.'/resources/views/layouts/admin.blade.php';

    expect($classPath)->toBeFile()
        ->and($layoutPath)->toBeFile();
});

it('prompts for component name when omitted', function () {
    $this->artisan('module:livewire', [
        '--module' => 'Blog',
    ])
        ->expectsQuestion('What should the Livewire component be named?', 'UserProfile')
        ->expectsOutputToContain('Livewire component [UserProfile] created successfully in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/UserProfile.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:livewire', [
        'name' => 'UserProfile',
    ])
        ->expectsChoice('Which module should this belong to?', 'Blog', ['Blog', 'Zeta'])
        ->expectsOutputToContain('Livewire component [UserProfile] created successfully in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/UserProfile.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:livewire', [
        'name' => 'PostList',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when component already exists', function () {
    $this->artisan('module:livewire', [
        'name' => 'DuplicateComp',
        '--module' => 'Blog',
    ])->assertSuccessful();

    $this->artisan('module:livewire', [
        'name' => 'DuplicateComp',
        '--module' => 'Blog',
    ])
        ->expectsOutputToContain('already exists in module [Blog]')
        ->assertFailed();
});

it('creates an SFC component with schema fields and auto-infers sfc type via --schema option', function () {
    $this->writeEnumFixture($this->blogModule, 'PostStatus');
    $this->artisan('module:livewire', [
        'name' => 'Posts/Create',
        '--module' => 'Blog',
        '--schema' => 'title:string,content:text,status:enum(PostStatus),is_featured:boolean',
        '--action' => 'CreatePost',
    ])
        ->expectsOutputToContain('Livewire component [Posts/create] created successfully in module [Blog].')
        ->expectsOutputToContain('Type: sfc')
        ->assertSuccessful();

    $sfcPath = $this->blogModule->path.'/Livewire/Posts/⚡create.blade.php';
    expect($sfcPath)->toBeFile();

    $content = File::get($sfcPath);
    expect($content)
        ->toContain('public CreateForm $form;')
        ->toContain('<form wire:submit="save"')
        ->toContain('wire:model="form.title"')
        ->toContain('wire:model="form.content"')
        ->toContain('wire:model="form.status"')
        ->toContain('wire:model="form.is_featured"');

    $form = File::get($this->blogModule->path.'/Livewire/Forms/Posts/CreateForm.php');
    expect($form)
        ->toContain("public string \$title = '';")
        ->toContain('public bool $is_featured = false;')
        ->toContain('return CreatePostInput::rules();')
        ->and($this->blogModule->path.'/Actions/CreatePostAction.php')->toBeFile();
});

it('has no -s shortcut for --schema', function () {
    expect(fn () => Artisan::call('module:livewire', ['name' => 'Posts/Create', '--module' => 'Blog', '-s' => 'title:string', '--action' => 'CreatePost']))
        ->toThrow(InvalidOptionException::class, 'The "-s" option does not exist.');
});

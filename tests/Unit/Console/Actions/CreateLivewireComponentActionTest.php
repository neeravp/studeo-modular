<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\LivewireComponentType;
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

test('it creates a classic component by default with class, blade view, and feature test', function () {
    $action = app(CreateLivewireComponentAction::class);

    $result = $action->execute($this->blogModule, 'PostList');

    $component = $result->component;
    expect($component->name)->toBe('PostList')
        ->and($component->alias)->toBe('post-list')
        ->and($component->type)->toBe('classic')
        ->and($component->isDirectoryBased())->toBeFalse()
        ->and($component->aliases())->toBe(['blog.post-list', 'blog::post-list']);

    $classPath = $this->blogModule->path.'/Livewire/PostList.php';
    $viewPath = $this->blogModule->path.'/resources/views/livewire/post-list.blade.php';
    $testPath = $this->blogModule->path.'/tests/Feature/Livewire/PostListTest.php';

    expect($classPath)->toBeFile()
        ->and($viewPath)->toBeFile()
        ->and($testPath)->toBeFile()
        ->and($result->createdFiles)->toHaveCount(3);

    $classContent = File::get($classPath);
    expect($classContent)
        ->toContain('namespace Studeo\Blog\Livewire;')
        ->toContain('use Livewire\Component;')
        ->toContain('class PostList extends Component')
        ->toContain("return view('blog::livewire.post-list');");

    $testContent = File::get($testPath);
    expect($testContent)
        ->toContain('use Livewire\Livewire;')
        ->not->toContain('use Studeo\Blog')
        ->toContain("Livewire::test('blog.post-list')");
});

test('it creates an MFC component when type is Mfc', function () {
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(type: LivewireComponentType::Mfc);
    $result = $action->execute($this->blogModule, 'PostList', $options);

    $component = $result->component;
    expect($component->name)->toBe('post-list')
        ->and($component->alias)->toBe('post-list')
        ->and($component->type)->toBe('mfc')
        ->and($component->isDirectoryBased())->toBeTrue()
        ->and($component->aliases())->toBe(['blog.post-list', 'blog::post-list']);

    $dir = $this->blogModule->path.'/Livewire/⚡post-list';
    expect($dir.'/post-list.php')->toBeFile()
        ->and($dir.'/post-list.blade.php')->toBeFile()
        ->and($dir.'/post-list.test.php')->toBeFile()
        ->and($this->blogModule->path.'/Livewire/PostList')->not->toBeDirectory()
        ->and($component->path)->toBe(realpath($dir))
        ->and($component->target)->toBe(realpath($dir));

    $classContent = File::get($dir.'/post-list.php');
    expect($classContent)
        ->toContain('use Livewire\Component;')
        ->toContain('new class extends Component')
        ->not->toContain('namespace')
        ->not->toContain('//');

    $testContent = File::get($dir.'/post-list.test.php');
    expect($testContent)
        ->toContain('use Livewire\Livewire;')
        ->not->toContain('use Studeo')
        ->toContain("Livewire::test('blog.post-list')");
});

test('it creates an SFC single-file component when type is Sfc', function () {
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(type: LivewireComponentType::Sfc);
    $result = $action->execute($this->blogModule, 'QuickStatus', $options);

    $sfcPath = $this->blogModule->path.'/Livewire/⚡quick-status.blade.php';
    $testPath = $this->blogModule->path.'/tests/Feature/Livewire/QuickStatusTest.php';

    expect($sfcPath)->toBeFile()
        ->and($testPath)->toBeFile()
        ->and($result->component->type)->toBe('sfc');

    $content = File::get($sfcPath);
    expect($content)
        ->toContain('use Livewire\Component;')
        ->toContain('new class extends Component')
        ->and(File::get($testPath))->toContain("Livewire::test('blog.quick-status')")->not->toContain('use Studeo');
});

test('it creates a Livewire SFC component with a Form, its Action and Input, and form inputs', function () {
    $this->writeEnumFixture($this->blogModule, 'PostStatus');
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(
        type: LivewireComponentType::Sfc,
        schema: 'title:string,content:text,status:enum(PostStatus),is_featured:boolean',
        action: 'CreatePost',
    );
    $result = $action->execute($this->blogModule, 'Posts/Create', $options);

    $sfcPath = $this->blogModule->path.'/Livewire/Posts/⚡create.blade.php';
    $formPath = $this->blogModule->path.'/Livewire/Forms/Posts/CreateForm.php';

    expect($sfcPath)->toBeFile()
        ->and($formPath)->toBeFile()
        ->and($this->blogModule->path.'/Actions/CreatePostAction.php')->toBeFile()
        ->and($this->blogModule->path.'/Data/CreatePostInput.php')->toBeFile()
        ->and($result->component->type)->toBe('sfc')
        ->and($result->createdFiles)->toContain($formPath, $this->blogModule->path.'/Actions/CreatePostAction.php');

    $form = File::get($formPath);
    expect($form)
        ->toContain("public string \$title = '';")
        ->toContain("public string \$content = '';")
        ->toContain("public string \$status = '';")
        ->toContain('public bool $is_featured = false;')
        ->toContain('return CreatePostInput::rules();');

    $content = File::get($sfcPath);
    expect($content)
        ->toContain('public CreateForm $form;')
        ->toContain('app(CreatePostAction::class)($this->form->toInput(), $actor);')
        ->toContain('<form wire:submit="save" class="space-y-6">')
        ->toContain('<label for="title"')
        ->toContain('wire:model="form.title"')
        ->toContain('<textarea')
        ->toContain('wire:model="form.content"')
        ->toContain('<select')
        ->toContain('wire:model="form.status"')
        ->toContain('@foreach (\Studeo\Blog\Enums\PostStatus::cases() as $case)')
        ->toContain('type="checkbox"')
        ->toContain('wire:model="form.is_featured"')
        ->toContain("@error('form.title')")
        ->toContain('Save');
});

test('it skips test file when test option is false', function () {
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(test: false);
    $result = $action->execute($this->blogModule, 'PostList', $options);

    $classPath = $this->blogModule->path.'/Livewire/PostList.php';
    $viewPath = $this->blogModule->path.'/resources/views/livewire/post-list.blade.php';
    $testPath = $this->blogModule->path.'/tests/Feature/Livewire/PostListTest.php';

    expect($classPath)->toBeFile()
        ->and($viewPath)->toBeFile()
        ->and($testPath)->not->toBeFile()
        ->and($result->createdFiles)->toHaveCount(2);
});

test('it creates scoped CSS and JS assets when assets option is true for MFC', function () {
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(type: LivewireComponentType::Mfc, assets: true);
    $result = $action->execute($this->blogModule, 'PostList', $options);

    $dir = $this->blogModule->path.'/Livewire/⚡post-list';
    expect($dir.'/post-list.css')->toBeFile()
        ->and($dir.'/post-list.js')->toBeFile()
        ->and($result->createdFiles)->toHaveCount(5);
});

test('it creates an inline classic component without a blade view', function () {
    $action = app(CreateLivewireComponentAction::class);

    $options = new LivewireComponentOptions(inline: true);
    $result = $action->execute($this->blogModule, 'InlineCounter', $options);

    $classPath = $this->blogModule->path.'/Livewire/InlineCounter.php';
    $viewPath = $this->blogModule->path.'/resources/views/livewire/inline-counter.blade.php';

    expect($classPath)->toBeFile()
        ->and($viewPath)->not->toBeFile();

    $classContent = File::get($classPath);
    expect($classContent)
        ->toContain('class InlineCounter extends Component')
        ->toContain("return <<<'HTML'");
});

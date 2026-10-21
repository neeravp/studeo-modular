<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\DiscoverLivewireComponentsAction;
use Studeo\Modular\Console\Actions\InstallModuleAction;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture;
    $this->composerPath = $this->fixture->composerPath;
    $this->rootModulesPath = $this->fixture->rootPath;
    $this->blogModule = $this->fixture->definition('Blog');
    $this->fixture->writeRoot();
    $this->fixture->modules('Blog');
});

afterEach(fn () => $this->fixture->tearDown());

test('forModule returns an empty array when Livewire directory does not exist', function () {
    $action = app(DiscoverLivewireComponentsAction::class);

    $components = $action->forModule($this->blogModule);

    expect($components)->toBe([]);
});

test('forModule discovers traditional PHP components with view file', function () {
    $livewireDir = $this->blogModule->path.'/Livewire';
    File::ensureDirectoryExists($livewireDir);

    $viewDir = $this->blogModule->path.'/resources/views/livewire';
    File::ensureDirectoryExists($viewDir);

    File::put($livewireDir.'/PostList.php', <<<'PHP'
<?php

namespace Studeo\Blog\Livewire;

use Livewire\Component;

class PostList extends Component {}
PHP
    );

    File::put($viewDir.'/post-list.blade.php', '<div>Posts</div>');

    $action = app(DiscoverLivewireComponentsAction::class);
    $components = $action->forModule($this->blogModule);

    expect($components)->toHaveKey('post-list');

    $component = $components['post-list'];
    expect($component)->toBeInstanceOf(LivewireComponentDefinition::class)
        ->and($component->name)->toBe('PostList')
        ->and($component->alias)->toBe('post-list')
        ->and($component->target)->toBe('Studeo\Blog\Livewire\PostList')
        ->and($component->type)->toBe('classic')
        ->and($component->isDirectoryBased())->toBeFalse()
        ->and($component->files)->toHaveCount(2)
        ->and($component->aliases())->toBe(['blog.post-list', 'blog::post-list']);
});

test('forModule discovers nested components in subdirectories', function () {
    $adminDir = $this->blogModule->path.'/Livewire/Admin';
    File::ensureDirectoryExists($adminDir);

    File::put($adminDir.'/UserTable.php', <<<'PHP'
<?php

namespace Studeo\Blog\Livewire\Admin;

use Livewire\Component;

class UserTable extends Component {}
PHP
    );

    $action = app(DiscoverLivewireComponentsAction::class);
    $components = $action->forModule($this->blogModule);

    expect($components)->toHaveKey('admin.user-table');

    $component = $components['admin.user-table'];
    expect($component->name)->toBe('Admin/UserTable')
        ->and($component->alias)->toBe('admin.user-table')
        ->and($component->target)->toBe('Studeo\Blog\Livewire\Admin\UserTable')
        ->and($component->aliases())->toBe(['blog.admin.user-table', 'blog::admin.user-table']);
});

test('forModule ignores Forms, Attributes, and loose test files', function () {
    $livewireDir = $this->blogModule->path.'/Livewire';
    File::ensureDirectoryExists($livewireDir.'/Forms');
    File::ensureDirectoryExists($livewireDir.'/Attributes');

    File::put($livewireDir.'/Forms/PostForm.php', '<?php namespace Studeo\Blog\Livewire\Forms; class PostForm {}');
    File::put($livewireDir.'/Attributes/Audit.php', '<?php namespace Studeo\Blog\Livewire\Attributes; class Audit {}');
    File::put($livewireDir.'/PostListTest.php', '<?php // test file');
    File::put($livewireDir.'/index.css', '/* css */');
    File::put($livewireDir.'/index.js', '// js');

    $action = app(DiscoverLivewireComponentsAction::class);
    $components = $action->forModule($this->blogModule);

    expect($components)->toBe([]);
});

test('execute discovers components across all installed modules', function () {
    app(InstallModuleAction::class)->execute($this->blogModule);

    $livewireDir = $this->blogModule->path.'/Livewire';
    File::ensureDirectoryExists($livewireDir);

    File::put($livewireDir.'/PostList.php', <<<'PHP'
<?php

namespace Studeo\Blog\Livewire;

use Livewire\Component;

class PostList extends Component {}
PHP
    );

    $action = app(DiscoverLivewireComponentsAction::class);
    $components = $action->execute();

    expect($components)->toHaveKey('blog.post-list')
        ->and($components['blog.post-list'])->toBeInstanceOf(LivewireComponentDefinition::class);
});

test('find locates component definition by name or alias', function () {
    $livewireDir = $this->blogModule->path.'/Livewire/Admin';
    File::ensureDirectoryExists($livewireDir);

    File::put($livewireDir.'/UserTable.php', <<<'PHP'
<?php

namespace Studeo\Blog\Livewire\Admin;

use Livewire\Component;

class UserTable extends Component {}
PHP
    );

    $action = app(DiscoverLivewireComponentsAction::class);

    $foundByAlias = $action->find($this->blogModule, 'admin.user-table');
    $foundByName = $action->find($this->blogModule, 'Admin/UserTable');
    $notFound = $action->find($this->blogModule, 'NonExistent');

    expect($foundByAlias)->not->toBeNull()
        ->and($foundByAlias->alias)->toBe('admin.user-table')
        ->and($foundByName)->not->toBeNull()
        ->and($foundByName->alias)->toBe('admin.user-table')
        ->and($notFound)->toBeNull();
});

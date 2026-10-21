<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\MoveLivewireComponentAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\Enums\LivewireComponentType;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootModulesPath = $this->fixture->rootPath;
    $this->blogModule = $this->fixture->definition('Blog');
    $this->shopModule = $this->fixture->definition('Shop');
    $this->fixture->modules('Blog', 'Shop');
});

afterEach(fn () => $this->fixture->tearDown());

test('it renames a classic component within the same module', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $mover = app(MoveLivewireComponentAction::class);
    $result = $mover->execute($this->blogModule, 'PostList', $this->blogModule, 'AllPosts');

    $oldClass = $this->blogModule->path.'/Livewire/PostList.php';
    $oldView = $this->blogModule->path.'/resources/views/livewire/post-list.blade.php';
    $oldTest = $this->blogModule->path.'/tests/Feature/Livewire/PostListTest.php';

    $newClass = $this->blogModule->path.'/Livewire/AllPosts.php';
    $newView = $this->blogModule->path.'/resources/views/livewire/all-posts.blade.php';
    $newTest = $this->blogModule->path.'/tests/Feature/Livewire/AllPostsTest.php';

    expect($oldClass)->not->toBeFile()
        ->and($oldView)->not->toBeFile()
        ->and($oldTest)->not->toBeFile()
        ->and($newClass)->toBeFile()
        ->and($newView)->toBeFile()
        ->and($newTest)->toBeFile()
        ->and($result->to->name)->toBe('AllPosts');

    $classContent = File::get($newClass);
    expect($classContent)->toContain('class AllPosts extends Component')
        ->and($classContent)->toContain("view('blog::livewire.all-posts')");

    $testContent = File::get($newTest);
    expect($testContent)->not->toContain('use Studeo')
        ->and($testContent)->toContain("Livewire::test('blog.all-posts')");
});

test('it relocates a classic component into a nested directory within the same module', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $mover = app(MoveLivewireComponentAction::class);
    $result = $mover->execute($this->blogModule, 'PostList', $this->blogModule, 'Admin/PostList');

    $newClass = $this->blogModule->path.'/Livewire/Admin/PostList.php';
    $newView = $this->blogModule->path.'/resources/views/livewire/admin/post-list.blade.php';
    $newTest = $this->blogModule->path.'/tests/Feature/Livewire/Admin/PostListTest.php';

    expect($newClass)->toBeFile()
        ->and($newView)->toBeFile()
        ->and($newTest)->toBeFile()
        ->and($result->to->name)->toBe('Admin/PostList');

    $classContent = File::get($newClass);
    expect($classContent)->toContain('namespace Studeo\Blog\Livewire\Admin;')
        ->and($classContent)->toContain('class PostList extends Component')
        ->and($classContent)->toContain("view('blog::livewire.admin.post-list')");

    $testContent = File::get($newTest);
    expect($testContent)->not->toContain('use Studeo')
        ->and($testContent)->toContain("Livewire::test('blog.admin.post-list')");
});

test('it moves a classic component across modules with layout rewriting', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'Pages/PostList', new LivewireComponentOptions(
        page: true,
        layout: 'app',
    ));

    $mover = app(MoveLivewireComponentAction::class);
    $result = $mover->execute($this->blogModule, 'Pages/PostList', $this->shopModule, 'Pages/ProductList');

    $oldClass = $this->blogModule->path.'/Livewire/Pages/PostList.php';
    $newClass = $this->shopModule->path.'/Livewire/Pages/ProductList.php';
    $newView = $this->shopModule->path.'/resources/views/livewire/pages/product-list.blade.php';
    $newTest = $this->shopModule->path.'/tests/Feature/Livewire/Pages/ProductListTest.php';
    $targetLayout = $this->shopModule->path.'/resources/views/layouts/app.blade.php';

    expect($oldClass)->not->toBeFile()
        ->and($newClass)->toBeFile()
        ->and($newView)->toBeFile()
        ->and($newTest)->toBeFile()
        ->and($targetLayout)->toBeFile()
        ->and($result->to->name)->toBe('Pages/ProductList');

    $classContent = File::get($newClass);
    expect($classContent)->toContain('namespace Studeo\Shop\Livewire\Pages;')
        ->and($classContent)->toContain('class ProductList extends Component')
        ->and($classContent)->toContain("view('shop::livewire.pages.product-list')")
        ->and($classContent)->toContain("#[Layout('shop::layouts.app')]");

    $testContent = File::get($newTest);
    expect($testContent)->not->toContain('use Studeo')
        ->and($testContent)->toContain("Livewire::test('shop.pages.product-list')");
});

test('it moves an MFC component directory within and across modules', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard', new LivewireComponentOptions(
        type: LivewireComponentType::Mfc,
        assets: true,
    ));

    $sourceDir = $this->blogModule->path.'/Livewire/⚡post-card';
    expect($sourceDir)->toBeDirectory();

    $mover = app(MoveLivewireComponentAction::class);
    $result = $mover->execute($this->blogModule, 'PostCard', $this->shopModule, 'ProductCard');

    $targetDir = $this->shopModule->path.'/Livewire/⚡product-card';

    expect($sourceDir)->not->toBeDirectory()
        ->and($targetDir)->toBeDirectory()
        ->and(array_map('basename', File::files($targetDir)))->toEqualCanonicalizing(['product-card.php', 'product-card.blade.php', 'product-card.test.php', 'product-card.css', 'product-card.js'])
        ->and($result->to->type)->toBe('mfc')
        ->and(File::get($targetDir.'/product-card.test.php'))->toContain("Livewire::test('shop.product-card')")->not->toContain('blog.post-card');
});

test('it moves an SFC component and its test file', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'QuickNotice', new LivewireComponentOptions(
        type: LivewireComponentType::Sfc,
    ));

    $sourceFile = $this->blogModule->path.'/Livewire/⚡quick-notice.blade.php';
    expect($sourceFile)->toBeFile();

    $mover = app(MoveLivewireComponentAction::class);
    $result = $mover->execute($this->blogModule, 'QuickNotice', $this->shopModule, 'FlashNotice');

    $targetFile = $this->shopModule->path.'/Livewire/⚡flash-notice.blade.php';
    $targetTest = $this->shopModule->path.'/tests/Feature/Livewire/FlashNoticeTest.php';

    expect($sourceFile)->not->toBeFile()
        ->and($targetFile)->toBeFile()
        ->and($targetTest)->toBeFile();

    $testContent = File::get($targetTest);
    expect($testContent)->not->toContain('use Studeo')
        ->and($testContent)->toContain("Livewire::test('shop.flash-notice')");
});

test('it prunes empty parent directories when moving a nested component', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'Nested/Deep/Component');

    $deepDir = $this->blogModule->path.'/Livewire/Nested/Deep';
    $nestedDir = $this->blogModule->path.'/Livewire/Nested';
    $livewireBaseDir = $this->blogModule->path.'/Livewire';

    expect($deepDir)->toBeDirectory();

    $mover = app(MoveLivewireComponentAction::class);
    $mover->execute($this->blogModule, 'Nested/Deep/Component', $this->blogModule, 'RootComponent');

    expect($deepDir)->not->toBeDirectory()
        ->and($nestedDir)->not->toBeDirectory()
        ->and($livewireBaseDir)->toBeDirectory()
        ->and($livewireBaseDir.'/RootComponent.php')->toBeFile();
});

test('it throws an exception when moving to the same name in the same module', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $mover = app(MoveLivewireComponentAction::class);
    $mover->execute($this->blogModule, 'PostList', $this->blogModule, 'PostList');
})->throws(RuntimeException::class, 'Cannot move component [PostList] to the same name in module [Blog].');

test('it throws an exception when target component already exists', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');
    $creator->execute($this->blogModule, 'Existing');

    $mover = app(MoveLivewireComponentAction::class);
    $mover->execute($this->blogModule, 'PostList', $this->blogModule, 'Existing');
})->throws(RuntimeException::class, 'Livewire component [Existing] already exists in module [Blog].');

test('it throws an exception when source component does not exist', function () {
    $mover = app(MoveLivewireComponentAction::class);
    $mover->execute($this->blogModule, 'Ghost', $this->blogModule, 'Target');
})->throws(RuntimeException::class, 'Livewire component [Ghost] does not exist in module [Blog].');

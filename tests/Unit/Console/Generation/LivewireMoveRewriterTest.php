<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Generation\LivewireMoveRewriter;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blog = new ModuleDefinition(name: 'Blog', namespace: 'Studeo\\Blog', path: $this->rootModulesPath.'/Blog');
    $this->shop = new ModuleDefinition(name: 'Shop', namespace: 'Studeo\\Shop', path: $this->rootModulesPath.'/Shop');

    app(CreateModuleAction::class)->execute($this->blog);
    app(CreateModuleAction::class)->execute($this->shop);

    $this->rewriter = app(LivewireMoveRewriter::class);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

test('it renames the component in a view comment with the default suffix', function () {
    $content = "<div>\n{{-- PostList Livewire Component --}}\n</div>\n";

    expect($this->rewriter->rewriteBladeTemplateContent($content, 'PostList', 'AllPosts'))
        ->toBe("<div>\n{{-- AllPosts Livewire Component --}}\n</div>\n");
});

test('it renames every occurrence and uses the last segment of nested names', function () {
    $content = 'PostList Livewire Component and PostList Livewire Component';

    expect($this->rewriter->rewriteBladeTemplateContent($content, 'Admin/PostList', 'Reports/AllPosts'))
        ->toBe('AllPosts Livewire Component and AllPosts Livewire Component');
});

test('it renames with a custom suffix and leaves other suffixes alone', function () {
    $content = 'PostList Livewire 4 Component / PostList Livewire Component';

    expect($this->rewriter->rewriteBladeTemplateContent($content, 'PostList', 'AllPosts', 'Livewire 4 Component'))
        ->toBe('AllPosts Livewire 4 Component / PostList Livewire Component');
});

test('it returns view content unchanged when the name is absent', function () {
    expect($this->rewriter->rewriteBladeTemplateContent('<div>Other Livewire Component</div>', 'PostList', 'AllPosts'))
        ->toBe('<div>Other Livewire Component</div>')
        ->and($this->rewriter->rewriteBladeTemplateContent('', 'PostList', 'AllPosts'))->toBe('');
});

test('it rewrites the component alias in test content', function () {
    $content = "Livewire::test('blog.post-list')->assertOk();\nLivewire::test('blog.post-list')->assertSee('x');\n";

    expect($this->rewriter->rewriteTestContent($content, 'blog.post-list', 'shop.all-posts'))
        ->toBe("Livewire::test('shop.all-posts')->assertOk();\nLivewire::test('shop.all-posts')->assertSee('x');\n");
});

test('it leaves similar aliases and other content in test content alone', function () {
    $content = "Livewire::test('blog.post-list-extra');\nLivewire::test(\"blog.post-list\");\n// blog.post-list\n";

    expect($this->rewriter->rewriteTestContent($content, 'blog.post-list', 'shop.all-posts'))->toBe($content)
        ->and($this->rewriter->rewriteTestContent('', 'blog.post-list', 'shop.all-posts'))->toBe('');
});

test('it keeps layout content unchanged within one module and creates no layout', function () {
    $content = "#[Layout('blog::layouts.app')]\nclass PostList {}\n";

    expect($this->rewriter->rewriteLayoutAttribute($content, $this->blog, $this->blog))->toBe($content);
});

test('it rewrites the layout module key and ensures the layout in the target module', function () {
    $content = "#[Layout('blog::layouts.admin')]\nclass PostList {}\n";
    $layout = $this->shop->path.'/resources/views/layouts/admin.blade.php';

    $rewritten = $this->rewriter->rewriteLayoutAttribute($content, $this->blog, $this->shop);

    expect($rewritten)->toBe("#[Layout('shop::layouts.admin')]\nclass PostList {}\n")
        ->and($layout)->toBeFile();
});

test('it leaves content without a layout attribute unchanged across modules', function () {
    $content = "class PostList {}\n";

    expect($this->rewriter->rewriteLayoutAttribute($content, $this->blog, $this->shop))->toBe($content)
        ->and($this->shop->path.'/resources/views/layouts')->not->toBeDirectory();
});

test('it rewrites a layout attribute that carries arguments', function () {
    $content = "#[Layout('blog::layouts.app', ['title' => 'x'])]\n";

    expect($this->rewriter->rewriteLayoutAttribute($content, $this->blog, $this->shop))
        ->toBe("#[Layout('shop::layouts.app', ['title' => 'x'])]\n");
});

test('it writes the new file, removes the old one and returns the mapping', function () {
    $source = $this->blog->path.'/Livewire/PostList.php';
    $target = $this->shop->path.'/Livewire/Deep/AllPosts.php';
    File::ensureDirectoryExists(dirname($source));
    File::put($source, 'old');

    $moved = $this->rewriter->replaceAndRelocateFile($source, $target, 'new', $this->blog->path.'/Livewire');

    expect($moved)->toBe([$source => $target])
        ->and($source)->not->toBeFile()
        ->and(File::get($target))->toBe('new');
});

test('it prunes emptied source directories up to the boundary and keeps the rest', function () {
    $boundary = $this->blog->path.'/Livewire';
    File::ensureDirectoryExists($boundary.'/Admin/Reports');
    File::ensureDirectoryExists($boundary.'/Keep');
    File::put($boundary.'/Admin/Reports/Table.php', 'old');
    File::put($boundary.'/Keep/Other.php', 'other');
    File::put($boundary.'/Admin/Sibling.php', 'sibling');

    $this->rewriter->replaceAndRelocateFile($boundary.'/Admin/Reports/Table.php', $this->shop->path.'/Livewire/Table.php', 'new', $boundary);

    expect($boundary.'/Admin/Reports')->not->toBeDirectory()
        ->and($boundary.'/Admin/Sibling.php')->toBeFile()
        ->and($boundary.'/Keep/Other.php')->toBeFile()
        ->and($boundary)->toBeDirectory();
});

test('it prunes every emptied directory down to the boundary itself', function () {
    $boundary = $this->blog->path.'/Livewire';
    File::ensureDirectoryExists($boundary.'/Admin/Reports');
    File::put($boundary.'/Admin/Reports/Table.php', 'old');

    $this->rewriter->replaceAndRelocateFile($boundary.'/Admin/Reports/Table.php', $this->blog->path.'/Livewire/Table.php', 'new', $boundary);

    expect($boundary.'/Admin')->not->toBeDirectory()
        ->and($boundary.'/Table.php')->toBeFile();
});

test('it moves nothing when the component has no feature test', function () {
    expect($this->rewriter->moveFeatureTestIfExists($this->blog, 'PostList', $this->shop, 'AllPosts'))->toBe([])
        ->and($this->shop->path.'/tests/Feature/Livewire/AllPostsTest.php')->not->toBeFile();
});

test('it moves a feature test and rewrites its alias', function () {
    $source = $this->blog->path.'/tests/Feature/Livewire/PostListTest.php';
    $target = $this->shop->path.'/tests/Feature/Livewire/AllPostsTest.php';
    File::ensureDirectoryExists(dirname($source));
    File::put($source, "Livewire::test('blog.post-list');\n");
    $canonicalSource = realpath($source);

    $moved = $this->rewriter->moveFeatureTestIfExists($this->blog, 'PostList', $this->shop, 'AllPosts');

    expect($moved)->toBe([$canonicalSource => $target])
        ->and($source)->not->toBeFile()
        ->and(File::get($target))->toBe("Livewire::test('shop.all-posts');\n");
});

test('it finds the feature test a generator wrote under the studly name', function () {
    $source = $this->blog->path.'/tests/Feature/Livewire/Admin/PostListTest.php';
    File::ensureDirectoryExists(dirname($source));
    File::put($source, "Livewire::test('blog.admin.post-list');\n");

    $moved = $this->rewriter->moveFeatureTestIfExists($this->blog, 'admin/post-list', $this->blog, 'admin/all-posts');

    expect($moved)->toHaveCount(1)
        ->and($source)->not->toBeFile()
        ->and(File::get($this->blog->path.'/tests/Feature/Livewire/admin/all-postsTest.php'))
        ->toBe("Livewire::test('blog.admin.all-posts');\n");
});

test('it does not look for a feature test under a spelling no generator writes', function () {
    $source = $this->blog->path.'/tests/Feature/Livewire/admin/post-listTest.php';
    File::ensureDirectoryExists(dirname($source));
    File::put($source, "Livewire::test('blog.admin.post-list');\n");

    expect($this->rewriter->moveFeatureTestIfExists($this->blog, 'admin/post-list', $this->blog, 'admin/all-posts'))->toBe([])
        ->and($source)->toBeFile();
});

test('it prunes the directories a moved feature test leaves empty', function () {
    $tests = $this->blog->path.'/tests/Feature/Livewire';
    File::ensureDirectoryExists($tests.'/Admin');
    File::put($tests.'/Admin/ReportTest.php', "Livewire::test('blog.admin.report');\n");

    $this->rewriter->moveFeatureTestIfExists($this->blog, 'Admin/Report', $this->shop, 'Report');

    expect($tests.'/Admin')->not->toBeDirectory()
        ->and($tests)->toBeDirectory()
        ->and(File::get($this->shop->path.'/tests/Feature/Livewire/ReportTest.php'))->toBe("Livewire::test('shop.report');\n");
});

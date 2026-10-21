<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\LivewirePaths;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/livewire_paths_'.uniqid();
    $this->files = new Filesystem;
    $this->files->ensureDirectoryExists($this->root.'/BlogPosts');
    $this->paths = new LivewirePaths($this->files);
    $this->module = new ModuleDefinition(name: 'BlogPosts', namespace: 'Studeo\\BlogPosts', path: $this->root.'/BlogPosts');
    $this->bare = new ModuleDefinition(name: 'Blog', namespace: 'Blog', path: $this->root.'/Blog');
});

afterEach(function () {
    $this->files->deleteDirectory($this->root);
});

function fromRoot(string $root, string $relative): string
{
    return $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

test('it kebab-cases names and paths into an alias', function (string $input, string $expected) {
    expect($this->paths->formatKebabPath($input))->toBe($expected);
})->with([
    'single word' => ['Table', 'table'],
    'two words' => ['UserTable', 'user-table'],
    'nested with slash' => ['Admin/UserTable', 'admin.user-table'],
    'four levels' => ['Admin/Reports/Monthly/SalesTable', 'admin.reports.monthly.sales-table'],
    'backslashes' => ['Admin\\UserTable', 'admin.user-table'],
    'dots' => ['admin.user-table', 'admin.user-table'],
    'empty segments are dropped' => ['/Admin//UserTable/', 'admin.user-table'],
    'digits' => ['Step2Form', 'step2-form'],
    'unicode' => ['Ünïcode/Tâble', 'ünïcode.tâble'],
    'empty' => ['', ''],
]);

test('it builds the three livewire directories of a module', function () {
    expect($this->paths->classesLivewirePath($this->module))->toBe($this->root.'/BlogPosts'.DIRECTORY_SEPARATOR.'Livewire')
        ->and($this->paths->viewsLivewirePath($this->module))->toBe(fromRoot($this->root.'/BlogPosts', 'resources/views/livewire'))
        ->and($this->paths->testsLivewirePath($this->module))->toBe(fromRoot($this->root.'/BlogPosts', 'tests/Feature/Livewire'));
});

test('it resolves the classic class, view and feature test paths', function () {
    $module = $this->root.'/BlogPosts';

    expect($this->paths->resolveClassicClassPath($this->module, 'Admin/Reports/Table'))->toBe(fromRoot($module, 'Livewire/Admin/Reports/Table.php'))
        ->and($this->paths->resolveClassicClassPath($this->module, 'PostList'))->toBe(fromRoot($module, 'Livewire/PostList.php'))
        ->and($this->paths->resolveClassicViewPath($this->module, 'Admin/Reports/Table'))->toBe(fromRoot($module, 'resources/views/livewire/admin/reports/table.blade.php'))
        ->and($this->paths->resolveClassicViewPath($this->module, 'PostList'))->toBe(fromRoot($module, 'resources/views/livewire/post-list.blade.php'))
        ->and($this->paths->resolveFeatureTestPath($this->module, 'Admin/Reports/Table'))->toBe(fromRoot($module, 'tests/Feature/Livewire/Admin/Reports/TableTest.php'))
        ->and($this->paths->resolveFeatureTestPath($this->module, 'PostList'))->toBe(fromRoot($module, 'tests/Feature/Livewire/PostListTest.php'));
});

test('it resolves the single-file and multi-file component paths', function () {
    $module = $this->root.'/BlogPosts';

    expect($this->paths->resolveSfcPath($this->module, 'PostList'))->toBe(fromRoot($module, 'Livewire/⚡post-list.blade.php'))
        ->and($this->paths->resolveSfcPath($this->module, 'Admin/Reports/UserTable'))->toBe(fromRoot($module, 'Livewire/Admin/Reports/⚡user-table.blade.php'))
        ->and($this->paths->resolveMfcDirectory($this->module, 'PostList'))->toBe(fromRoot($module, 'Livewire/⚡post-list'))
        ->and($this->paths->resolveMfcDirectory($this->module, 'Admin/Reports/UserTable'))->toBe(fromRoot($module, 'Livewire/Admin/Reports/⚡user-table'))
        ->and($this->paths->mfcFileBase('PostList'))->toBe('post-list')
        ->and($this->paths->mfcFileBase('Admin/Reports/UserTable'))->toBe('user-table');
});

test('it names a component by module key and alias', function () {
    expect($this->paths->livewireAlias($this->module, 'PostList'))->toBe('blog-posts.post-list')
        ->and($this->paths->livewireAlias($this->module, 'Admin/Reports/UserTable'))->toBe('blog-posts.admin.reports.user-table')
        ->and($this->paths->livewireAlias($this->bare, 'Table'))->toBe('blog.table');
});

test('it builds the classic namespace and class name', function () {
    expect($this->paths->buildClassicNamespace($this->module, 'PostList'))->toBe('Studeo\\BlogPosts\\Livewire')
        ->and($this->paths->buildClassicNamespace($this->module, 'Admin/Reports/Table'))->toBe('Studeo\\BlogPosts\\Livewire\\Admin\\Reports')
        ->and($this->paths->buildClassicNamespace($this->bare, 'PostList'))->toBe('Blog\\Livewire')
        ->and($this->paths->buildClassicFqcn($this->module, 'PostList'))->toBe('Studeo\\BlogPosts\\Livewire\\PostList')
        ->and($this->paths->buildClassicFqcn($this->bare, 'Admin/Table'))->toBe('Blog\\Livewire\\Admin\\Table');
});

test('it finds no feature test when none exists', function () {
    expect($this->paths->resolveExistingFeatureTestPath($this->module, 'PostList'))->toBeNull()
        ->and($this->paths->resolveExistingFeatureTestPath($this->module, 'Admin/Reports/Table'))->toBeNull();
});

test('it finds the feature test written under the name as given', function () {
    $path = $this->paths->resolveFeatureTestPath($this->module, 'PostList');
    $this->files->ensureDirectoryExists(dirname($path));
    $this->files->put($path, '<?php');

    expect($this->paths->resolveExistingFeatureTestPath($this->module, 'PostList'))->toBe(realpath($path));
});

test('it finds the feature test written under the studly name', function () {
    $path = $this->paths->resolveFeatureTestPath($this->module, 'Admin/PostList');
    $this->files->ensureDirectoryExists(dirname($path));
    $this->files->put($path, '<?php');

    expect($this->paths->resolveExistingFeatureTestPath($this->module, 'admin/post-list'))->toBe(realpath($path));
});

test('it does not look for a spelling no generator writes', function () {
    $path = fromRoot($this->root.'/BlogPosts', 'tests/Feature/Livewire/admin/post-listTest.php');
    $this->files->ensureDirectoryExists(dirname($path));
    $this->files->put($path, '<?php');

    expect($this->paths->resolveExistingFeatureTestPath($this->module, 'Admin/PostList'))->toBeNull()
        ->and($this->paths->resolveExistingFeatureTestPath($this->module, 'admin/post-list'))->toBeNull();
});

test('it reports the generated spelling when another spelling is also on disk', function () {
    $other = $this->paths->resolveFeatureTestPath($this->module, 'post-list');
    $studly = $this->paths->resolveFeatureTestPath($this->module, 'PostList');
    $this->files->ensureDirectoryExists(dirname($other));
    $this->files->put($studly, '<?php');
    $this->files->put($other, '<?php');

    expect($this->paths->resolveExistingFeatureTestPath($this->module, 'post-list'))->toBe(realpath($studly))
        ->and($this->paths->resolveExistingFeatureTestPath($this->module, 'PostList'))->toBe(realpath($studly));
});

test('it ignores a directory named like the feature test', function () {
    $path = $this->paths->resolveFeatureTestPath($this->module, 'PostList');
    $this->files->ensureDirectoryExists($path);

    expect($this->paths->resolveExistingFeatureTestPath($this->module, 'PostList'))->toBeNull();
});

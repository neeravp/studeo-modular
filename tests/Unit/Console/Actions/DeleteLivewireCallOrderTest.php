<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\DeleteLivewireComponentAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\LivewireComponentType;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');

    app(CreateModuleAction::class)->execute($this->blogModule);
    $this->writeEnumFixture($this->blogModule, 'PostStatus');
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * Builds what a case deletes: the component under test and whatever else the module holds.
 */
function prepareLivewireDelete(string $scenario, ModuleDefinition $module): void
{
    $create = fn (string $name, array $extra = []) => app(CreateLivewireComponentAction::class)->execute($module, $name, new LivewireComponentOptions(...$extra));
    $form = ['type' => LivewireComponentType::Sfc, 'schema' => 'title:string,status:enum(PostStatus)', 'action' => 'CreatePost'];

    match ($scenario) {
        'classic' => $create('PostList'),
        'nested classic' => $create('Admin/Settings/Profile'),
        'multi-file with assets' => $create('PostList', ['type' => LivewireComponentType::Mfc, 'assets' => true]),
        'nested multi-file' => $create('Admin/PostList', ['type' => LivewireComponentType::Mfc]),
        'multi-file with a subfolder' => addLivewireSubfolder($create, $module),
        'single-file' => $create('QuickStatus', ['type' => LivewireComponentType::Sfc]),
        'nested form' => $create('Posts/Create', $form),
        'flat form' => $create('CreatePost', $form),
        'shared form' => shareLivewireForm($create, $form, $module),
        'classic without its view' => removeLivewireView($create, $module),
        default => null,
    };
}

function addLivewireSubfolder(Closure $create, ModuleDefinition $module): void
{
    $create('Admin/PostList', ['type' => LivewireComponentType::Mfc]);
    File::ensureDirectoryExists("{$module->path}/Livewire/Admin/⚡post-list/sub");
    File::put("{$module->path}/Livewire/Admin/⚡post-list/sub/x.txt", 'x');
}

function shareLivewireForm(Closure $create, array $form, ModuleDefinition $module): void
{
    $create('CreatePost', $form);
    File::put("{$module->path}/Livewire/Other.php", "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nuse Studeo\\Blog\\Livewire\\Forms\\CreatePostForm;\n\nclass Other\n{\n    public CreatePostForm \$form;\n}\n");
    File::put("{$module->path}/Livewire/Another.php", "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nclass Another\n{\n    public function f(): string\n    {\n        return \\Studeo\\Blog\\Livewire\\Forms\\CreatePostForm::class;\n    }\n}\n");
}

function removeLivewireView(Closure $create, ModuleDefinition $module): void
{
    $create('PostList');
    File::delete("{$module->path}/resources/views/livewire/post-list.blade.php");
}

/**
 * Each case: the fixture, what exists, the component asked for, the files reported as deleted
 * (relative to the module), the notices (with <module> for the module path) and the failure.
 *
 * @return array<string, array{string, string, string, array<int, string>, array<int, string>, ?string}>
 */
function livewireDeleteCases(): array
{
    return [
        'delete-classic' => ['delete-classic', 'classic', 'PostList', ['Livewire/PostList.php', 'resources/views/livewire/post-list.blade.php', 'tests/Feature/Livewire/PostListTest.php'], [], null],
        'delete-classic-nested' => ['delete-classic-nested', 'nested classic', 'Admin/Settings/Profile', ['Livewire/Admin/Settings/Profile.php', 'resources/views/livewire/admin/settings/profile.blade.php', 'tests/Feature/Livewire/Admin/Settings/ProfileTest.php'], [], null],
        'delete-mfc-assets' => ['delete-mfc-assets', 'multi-file with assets', 'PostList', ['Livewire/⚡post-list/post-list.blade.php', 'Livewire/⚡post-list/post-list.css', 'Livewire/⚡post-list/post-list.js', 'Livewire/⚡post-list/post-list.php', 'Livewire/⚡post-list/post-list.test.php'], [], null],
        'delete-mfc-nested' => ['delete-mfc-nested', 'nested multi-file', 'Admin/PostList', ['Livewire/Admin/⚡post-list/post-list.blade.php', 'Livewire/Admin/⚡post-list/post-list.php', 'Livewire/Admin/⚡post-list/post-list.test.php'], [], null],
        'delete-mfc-subfolder' => ['delete-mfc-subfolder', 'multi-file with a subfolder', 'Admin/PostList', ['Livewire/Admin/⚡post-list/post-list.blade.php', 'Livewire/Admin/⚡post-list/post-list.php', 'Livewire/Admin/⚡post-list/post-list.test.php', 'Livewire/Admin/⚡post-list'], [], null],
        'delete-sfc' => ['delete-sfc', 'single-file', 'QuickStatus', ['Livewire/⚡quick-status.blade.php', 'tests/Feature/Livewire/QuickStatusTest.php'], [], null],
        'delete-sfc-form-nested' => ['delete-sfc-form-nested', 'nested form', 'Posts/Create', ['Livewire/Posts/⚡create.blade.php', 'tests/Feature/Livewire/Posts/CreateTest.php', 'Livewire/Forms/Posts/CreateForm.php'], [], null],
        'delete-sfc-form-flat' => ['delete-sfc-form-flat', 'flat form', 'CreatePost', ['Livewire/⚡create-post.blade.php', 'tests/Feature/Livewire/CreatePostTest.php', 'Livewire/Forms/CreatePostForm.php'], [], null],
        'delete-sfc-form-shared' => ['delete-sfc-form-shared', 'shared form', 'CreatePost', ['Livewire/⚡create-post.blade.php', 'tests/Feature/Livewire/CreatePostTest.php'], ['Kept Form [<module>/Livewire/Forms/CreatePostForm.php] because other files use it: <module>/Livewire/Another.php, <module>/Livewire/Other.php.'], null],
        'delete-classic-partial' => ['delete-classic-partial', 'classic without its view', 'PostList', ['Livewire/PostList.php', 'tests/Feature/Livewire/PostListTest.php'], [], null],
        'delete-error-missing' => ['delete-error-missing', 'nothing', 'Nope', [], [], 'Livewire component [Nope] does not exist in module [Blog].'],
    ];
}

it('asks the Filesystem for the same calls, in the same order, and reports the same files and notices', function (string $fixture, string $scenario, string $name, array $deleted, array $notices, ?string $failure) {
    prepareLivewireDelete($scenario, $this->blogModule);

    $files = orderedFilesystem();
    $delete = fn () => app(DeleteLivewireComponentAction::class)->execute($this->blogModule, $name);

    if ($failure !== null) {
        expect($delete)->toThrow(RuntimeException::class, $failure);
    } else {
        $result = $delete();
        expect(relativeTo($this->blogModule->path, $result->deletedFiles))->toBe($deleted)
            ->and(array_map(fn (string $notice): string => str_replace($this->blogModule->path, '<module>', $notice), $result->notices))->toBe($notices);
    }

    expect($files->calls)->toBe(callFixture($fixture));
})->with(livewireDeleteCases());

it('refuses a module that is not on disk before touching anything else', function () {
    $ghost = new ModuleDefinition('Ghost', 'Studeo\\Ghost', $this->rootModulesPath.'/Ghost');
    $files = orderedFilesystem();

    expect(fn () => app(DeleteLivewireComponentAction::class)->execute($ghost, 'PostList'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghost->path}].")
        ->and($files->calls)->toBe(callFixture('delete-error-module-missing'));
});

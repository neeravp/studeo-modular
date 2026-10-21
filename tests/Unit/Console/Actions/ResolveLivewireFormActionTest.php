<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\DiscoverLivewireComponentsAction;
use Studeo\Modular\Console\Actions\ResolveLivewireFormAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->path = config('modular.root_path').'/Blog';
    File::deleteDirectory($this->path);
    File::ensureDirectoryExists($this->path.'/Livewire/Forms');
    $this->module = new ModuleDefinition(name: 'Blog', namespace: 'Studeo\\Blog', path: $this->path);
    File::put($this->path.'/Livewire/Forms/EditPostForm.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire\\Forms;\n\nclass EditPostForm\n{\n}\n");
    $this->component = fn (string $body = 'public EditPostForm $form;') => File::put($this->path.'/Livewire/⚡edit-post.blade.php', "<?php\n\nuse Livewire\\Component;\nuse Studeo\\Blog\\Livewire\\Forms\\EditPostForm;\n\nnew class extends Component\n{\n    {$body}\n};\n?>\n\n<div></div>\n");
    $this->resolve = fn () => app(ResolveLivewireFormAction::class)->execute($this->module, app(DiscoverLivewireComponentsAction::class)->find($this->module, 'edit-post'));
});

afterEach(function () {
    File::deleteDirectory($this->path);
});

it('finds the Form of a component that declares it, with the Form class and path', function () {
    ($this->component)();

    $form = ($this->resolve)();

    expect($form->class)->toBe('Studeo\\Blog\\Livewire\\Forms\\EditPostForm')
        ->and(realpath($form->path))->toBe(realpath($this->path.'/Livewire/Forms/EditPostForm.php'))
        ->and($form->references)->toBe([])->and($form->shared())->toBeFalse();
});

it('finds a Form the component only imports', function () {
    ($this->component)('public int $count = 0;');

    expect(($this->resolve)())->not->toBeNull();
});

it('finds no Form when the component does not declare the file that carries its name', function () {
    ($this->component)('public int $count = 0;');
    File::put($this->path.'/Livewire/⚡edit-post.blade.php', "<?php\n\nuse Livewire\\Component;\n\nnew class extends Component\n{\n};\n?>\n\n<div></div>\n");

    expect(($this->resolve)())->toBeNull();
});

it('finds no Form when the Form file does not exist or the component is not single-file', function () {
    ($this->component)();
    File::delete($this->path.'/Livewire/Forms/EditPostForm.php');
    $missing = ($this->resolve)();
    File::put($this->path.'/Livewire/Forms/EditPostForm.php', '<?php class EditPostForm {}');
    File::delete($this->path.'/Livewire/⚡edit-post.blade.php');
    File::put($this->path.'/Livewire/EditPost.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nuse Studeo\\Blog\\Livewire\\Forms\\EditPostForm;\n\nclass EditPost\n{\n    public EditPostForm \$form;\n}\n");

    expect($missing)->toBeNull()
        ->and(app(ResolveLivewireFormAction::class)->execute($this->module, app(DiscoverLivewireComponentsAction::class)->find($this->module, 'EditPost')))->toBeNull();
});

it('lists the other php and blade files that name the Form as a whole word, FQCN or short, and ignores the component and its own files', function () {
    ($this->component)();
    File::ensureDirectoryExists($this->path.'/tests/Feature/Livewire');
    File::put($this->path.'/tests/Feature/Livewire/EditPostTest.php', "<?php\n\n// EditPostForm\n");
    File::ensureDirectoryExists($this->path.'/Http');
    File::put($this->path.'/Http/ByName.php', "<?php\n\nreturn \\Studeo\\Blog\\Livewire\\Forms\\EditPostForm::class;\n");
    File::ensureDirectoryExists($this->path.'/resources/views');
    File::put($this->path.'/resources/views/page.blade.php', "<div>@php(new EditPostForm)</div>\n");
    File::put($this->path.'/Http/Lookalikes.php', "<?php\n\n// EditPostFormRequest MyEditPostForm EditPostForms\n");

    $form = ($this->resolve)();

    expect(array_map(fn (string $path): string => str_replace(realpath($this->path).'/', '', $path), $form->references))->toEqualCanonicalizing(['Http/ByName.php', 'resources/views/page.blade.php'])
        ->and($form->shared())->toBeTrue();
});

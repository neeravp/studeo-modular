<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootComposerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->rootComposerPath) ? File::get($this->rootComposerPath) : null;
    File::put($this->rootComposerPath, json_encode(['name' => 'laravel/laravel', 'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
    $this->artisan('module:make', ['module' => 'Shop'])->assertSuccessful();
    $this->artisan('module:schema', ['name' => 'Article', '--module' => 'Blog', '--fields' => 'title:string,note:string:nullable,author_id:foreignId:context'])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'CreateArticle', '--module' => 'Blog', '--schema' => '@articles', '--action' => 'CreateArticle', '--context' => ['author_id=actor']])->assertSuccessful();
    $this->blog = $this->modulesPath.'/Blog';
    $this->component = $this->blog.'/Livewire/⚡create-article.blade.php';
    $this->form = $this->blog.'/Livewire/Forms/CreateArticleForm.php';
    $this->untouched = fn (): array => [md5_file($this->blog.'/Actions/CreateArticleAction.php'), md5_file($this->blog.'/Data/CreateArticleInput.php')];
    $this->untouchedBefore = ($this->untouched)();
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

function move(object $test, string $name, string $to, array $extra = []): mixed
{
    return $test->artisan('module:livewire:move', ['name' => $name, 'new-name' => $to, '--module' => 'Blog', '--force' => true, ...$extra]);
}

it('renames the Form with its component and rewrites the component to match', function () {
    move($this, 'create-article', 'PublishArticle')->expectsOutputToContain('Livewire/Forms/PublishArticleForm.php')->assertSuccessful();

    $form = File::get($this->blog.'/Livewire/Forms/PublishArticleForm.php');
    $component = File::get($this->blog.'/Livewire/⚡publish-article.blade.php');

    expect($this->form)->not->toBeFile()
        ->and($form)->toContain('namespace Studeo\Blog\Livewire\Forms;')->toContain('class PublishArticleForm extends Form')->not->toContain('CreateArticleForm')
        ->and($component)->toContain('use Studeo\Blog\Livewire\Forms\PublishArticleForm;')->toContain('public PublishArticleForm $form;')->not->toContain('CreateArticleForm')
        ->and(($this->untouched)())->toBe($this->untouchedBefore);
});

it('moves the Form into the mirrored folder and prunes the emptied Form folders when it moves back', function () {
    move($this, 'create-article', 'Posts/Create')->assertSuccessful();

    expect($this->blog.'/Livewire/Forms/Posts/CreateForm.php')->toBeFile()
        ->and(File::get($this->blog.'/Livewire/Forms/Posts/CreateForm.php'))->toContain('namespace Studeo\Blog\Livewire\Forms\Posts;')->toContain('class CreateForm extends Form')
        ->and(File::get($this->blog.'/Livewire/Posts/⚡create.blade.php'))->toContain('use Studeo\Blog\Livewire\Forms\Posts\CreateForm;')->toContain('public CreateForm $form;');

    move($this, 'posts/create', 'CreateArticle')->assertSuccessful();

    expect($this->form)->toBeFile()->and($this->blog.'/Livewire/Forms/Posts')->not->toBeDirectory()
        ->and($this->blog.'/Livewire/Forms')->toBeDirectory()
        ->and(File::get($this->component))->toContain('use Studeo\Blog\Livewire\Forms\CreateArticleForm;')
        ->and(($this->untouched)())->toBe($this->untouchedBefore);
});

it('moves the Form to the other module and keeps the Action and Input where they are', function () {
    move($this, 'create-article', 'CreateArticle', ['--to-module' => 'Shop'])->assertSuccessful();

    $component = File::get($this->modulesPath.'/Shop/Livewire/⚡create-article.blade.php');

    expect($this->form)->not->toBeFile()
        ->and(File::get($this->modulesPath.'/Shop/Livewire/Forms/CreateArticleForm.php'))->toContain('namespace Studeo\Shop\Livewire\Forms;')->toContain('use Studeo\Blog\Data\CreateArticleInput;')
        ->and($component)->toContain('use Studeo\Shop\Livewire\Forms\CreateArticleForm;')->toContain('use Studeo\Blog\Actions\CreateArticleAction;')
        ->and(($this->untouched)())->toBe($this->untouchedBefore);
});

it('deletes the component with its Form, prunes the emptied Form folders and leaves the Action and Input', function () {
    move($this, 'create-article', 'Posts/Create')->assertSuccessful();

    $this->artisan('module:livewire:delete', ['name' => 'posts/create', '--module' => 'Blog', '--force' => true])
        ->expectsOutputToContain('Livewire/Forms/Posts/CreateForm.php')->assertSuccessful();

    expect($this->blog.'/Livewire/Posts')->not->toBeDirectory()->and($this->blog.'/Livewire/Forms/Posts')->not->toBeDirectory()
        ->and(($this->untouched)())->toBe($this->untouchedBefore);
});

it('lists the Form among the files a confirmed delete will remove, and removes nothing when declined', function () {
    $this->artisan('module:livewire:delete', ['name' => 'create-article', '--module' => 'Blog'])
        ->expectsOutputToContain('Livewire/Forms/CreateArticleForm.php')
        ->expectsConfirmation('Are you sure you want to delete component [create-article]?', 'no')
        ->assertSuccessful();

    expect($this->form)->toBeFile()->and($this->component)->toBeFile();
});

it('keeps a Form another file references on delete and names that file', function () {
    File::put($this->blog.'/Livewire/Other.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nuse Studeo\\Blog\\Livewire\\Forms\\CreateArticleForm;\n\nclass Other\n{\n    public CreateArticleForm \$form;\n}\n");

    $this->artisan('module:livewire:delete', ['name' => 'create-article', '--module' => 'Blog', '--force' => true])
        ->expectsOutputToContain('Livewire/Other.php')->assertSuccessful();

    expect($this->component)->not->toBeFile()->and($this->form)->toBeFile();
});

it('leaves a Form another file references where it is on move, points the component at it and names that file', function () {
    File::put($this->blog.'/Livewire/Other.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nclass Other\n{\n    public function form(): string\n    {\n        return \\Studeo\\Blog\\Livewire\\Forms\\CreateArticleForm::class;\n    }\n}\n");

    move($this, 'create-article', 'PublishArticle')->expectsOutputToContain('Livewire/Other.php')->assertSuccessful();

    expect($this->form)->toBeFile()->and($this->blog.'/Livewire/Forms/PublishArticleForm.php')->not->toBeFile()
        ->and(File::get($this->blog.'/Livewire/⚡publish-article.blade.php'))->toContain('use Studeo\Blog\Livewire\Forms\CreateArticleForm;')->toContain('public CreateArticleForm $form;');
});

it('fails a move whose target Form already exists and moves nothing', function () {
    File::put($this->blog.'/Livewire/Forms/PublishArticleForm.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire\\Forms;\n\nclass PublishArticleForm\n{\n}\n");
    $before = md5_file($this->component);

    move($this, 'create-article', 'PublishArticle')->expectsOutputToContain('PublishArticleForm.php')->assertFailed();

    expect($this->component)->toBeFile()->and(md5_file($this->component))->toBe($before)->and($this->form)->toBeFile()
        ->and($this->blog.'/Livewire/⚡publish-article.blade.php')->not->toBeFile()
        ->and(File::get($this->blog.'/Livewire/Forms/PublishArticleForm.php'))->toContain("class PublishArticleForm\n{");
});

it('moves and deletes a component without a Form as before and never touches a Form-named file it does not declare', function () {
    $this->artisan('module:livewire', ['name' => 'Plain', '--module' => 'Blog', '--sfc' => true])->assertSuccessful();
    File::put($this->blog.'/Livewire/Forms/PlainForm.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire\\Forms;\n\nclass PlainForm\n{\n}\n");
    File::put($this->blog.'/Livewire/Forms/PlainerForm.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire\\Forms;\n\nclass PlainerForm\n{\n}\n");

    move($this, 'plain', 'Plainer')->assertSuccessful();
    $this->artisan('module:livewire:delete', ['name' => 'plainer', '--module' => 'Blog', '--force' => true])->assertSuccessful();

    expect($this->blog.'/Livewire/Forms/PlainForm.php')->toBeFile()->and($this->blog.'/Livewire/Forms/PlainerForm.php')->toBeFile()
        ->and($this->form)->toBeFile()
        ->and($this->blog.'/Livewire/⚡plain.blade.php')->not->toBeFile()->and($this->blog.'/Livewire/⚡plainer.blade.php')->not->toBeFile();
});

it('writes only inside the module directory', function () {
    move($this, 'create-article', 'Posts/Create')->assertSuccessful();
    $this->artisan('module:livewire:delete', ['name' => 'posts/create', '--module' => 'Blog', '--force' => true])->assertSuccessful();

    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

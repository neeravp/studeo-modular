<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    $this->writeEnumFixture($this->module, 'PostStatus');
    Artisan::call('module:schema', ['name' => 'Article', '--module' => 'Blog', '--fields' => 'slug:string:unique,title:string,status:enum(PostStatus):default(draft),author_id:foreignId:constrained(users):context']);
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function livewireForm(array $arguments): array
{
    $exitCode = Artisan::call('module:livewire', ['--module' => 'Blog', '--schema' => '@articles', ...$arguments]);

    return [$exitCode, Artisan::output()];
}

function moduleFileList(object $test): array
{
    return collect(File::allFiles($test->module->path))->map->getPathname()->sort()->values()->all();
}

it('needs --action for a schema, shows an example, and writes nothing', function () {
    $before = moduleFileList($this);

    [$exitCode, $output] = livewireForm(['name' => 'CreateArticle']);

    expect($exitCode)->toBe(1)->and($output)->toContain('--action=CreateArticle')->and(moduleFileList($this))->toBe($before);
});

it('reports a schema error before a missing --action, and writes nothing', function () {
    $before = moduleFileList($this);

    [$exitCode, $output] = livewireForm(['name' => 'CreateArticle', '--schema' => 'slug:strng']);

    expect($exitCode)->toBe(1)->and($output)->toContain('Invalid schema:')->and(moduleFileList($this))->toBe($before);
});

it('refuses a missing, unknown or non-actor --context, listing the valid forms, and writes nothing', function (array $context, string $expected) {
    $before = moduleFileList($this);

    [$exitCode, $output] = livewireForm(['name' => 'CreateArticle', '--action' => 'CreateArticle', '--context' => $context]);

    expect($exitCode)->toBe(1)->and($output)->toContain($expected)->toContain('--context=author_id=actor')->and(moduleFileList($this))->toBe($before);
})->with([
    'no source' => [[], 'author_id'],
    'unknown field' => [['author_id=actor', 'nope=actor'], '[nope]'],
    'not actor' => [['author_id=user'], '[user]'],
    'not a pair' => [['author_id'], '[author_id]'],
]);

it('generates the Action, its Input and the Form, and reports each', function () {
    [$exitCode, $output] = livewireForm(['name' => 'CreateArticle', '--action' => 'CreateArticle', '--context' => ['author_id=actor']]);

    expect($exitCode)->toBe(0)
        ->and($this->module->path.'/Actions/CreateArticleAction.php')->toBeFile()
        ->and($this->module->path.'/Data/CreateArticleInput.php')->toBeFile()
        ->and($this->module->path.'/Livewire/Forms/CreateArticleForm.php')->toBeFile()
        ->and($this->module->path.'/Livewire/⚡create-article.blade.php')->toBeFile()
        ->and($output)->toContain('Actions/CreateArticleAction.php')->toContain('Data/CreateArticleInput.php')->toContain('Forms/CreateArticleForm.php');
});

it('reuses an existing Action and Input without touching them, and says so', function () {
    Artisan::call('module:action', ['name' => 'CreateArticle', '--module' => 'Blog', '--schema' => '@articles']);
    $hashes = [md5_file($this->module->path.'/Actions/CreateArticleAction.php'), md5_file($this->module->path.'/Data/CreateArticleInput.php')];

    [$exitCode, $output] = livewireForm(['name' => 'CreateArticle', '--action' => 'CreateArticle', '--context' => ['author_id=actor']]);

    expect($exitCode)->toBe(0)
        ->and([md5_file($this->module->path.'/Actions/CreateArticleAction.php'), md5_file($this->module->path.'/Data/CreateArticleInput.php')])->toBe($hashes)
        ->and($output)->toContain('Reusing')->toContain('CreateArticleAction.php');
});

it('skips a form field the existing Input lacks, with a notice', function () {
    Artisan::call('module:dto', ['name' => 'CreateArticleInput', '--module' => 'Blog', '--schema' => 'slug:string,title:string,author_id:foreignId:context']);

    [$exitCode, $output] = livewireForm(['name' => 'CreateArticle', '--action' => 'CreateArticle', '--context' => ['author_id=actor']]);
    $form = File::get($this->module->path.'/Livewire/Forms/CreateArticleForm.php');

    expect($exitCode)->toBe(0)->and($output)->toContain('Skipped')->toContain('[status]')
        ->and($form)->toContain('public string $slug')->not->toContain('$status');
});

it('never overwrites an existing Form, and writes nothing else', function () {
    File::ensureDirectoryExists($this->module->path.'/Livewire/Forms');
    File::put($this->module->path.'/Livewire/Forms/CreateArticleForm.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire\\Forms;\n\nclass CreateArticleForm {}\n");
    $before = moduleFileList($this);

    [$exitCode, $output] = livewireForm(['name' => 'CreateArticle', '--action' => 'CreateArticle', '--context' => ['author_id=actor']]);

    expect($exitCode)->toBe(1)->and($output)->toContain('already exists')->and(moduleFileList($this))->toBe($before);
});

it('mirrors the component folders for a nested component', function () {
    livewireForm(['name' => 'Posts/CreateArticle', '--action' => 'CreateArticle', '--context' => ['author_id=actor']]);

    $form = File::get($this->module->path.'/Livewire/Forms/Posts/CreateArticleForm.php');
    $component = File::get($this->module->path.'/Livewire/Posts/⚡create-article.blade.php');

    expect($form)->toContain('namespace Studeo\\Blog\\Livewire\\Forms\\Posts;')->toContain('class CreateArticleForm extends Form')
        ->and($component)->toContain('use Studeo\\Blog\\Livewire\\Forms\\Posts\\CreateArticleForm;')->toContain('public CreateArticleForm $form;');
});

it('emits a complete component with the dot alias in its test and no placeholder text', function () {
    livewireForm(['name' => 'CreateArticle', '--action' => 'CreateArticle', '--context' => ['author_id=actor']]);
    $component = File::get($this->module->path.'/Livewire/⚡create-article.blade.php');
    $form = File::get($this->module->path.'/Livewire/Forms/CreateArticleForm.php');
    $test = File::get($this->module->path.'/tests/Feature/Livewire/CreateArticleTest.php');

    expect($component)
        ->toContain("        \$this->form->validate();\n\n        \$actor = auth()->user() ?? abort(401);\n")
        ->toContain("app(CreateArticleAction::class)(\$this->form->toInput(['author_id' => \$actor->getAuthIdentifier()]), \$actor);")
        ->toContain('catch (ValidationException $exception)')->toContain('$this->form->addActionErrors($exception);')
        ->toContain('use Illuminate\\Validation\\ValidationException;')->toContain('use Studeo\\Blog\\Actions\\CreateArticleAction;')
        ->toContain('wire:model="form.slug"')->toContain("@error('form.slug')")->not->toContain('wire:model="slug"')
        ->and($form)->toContain('use Illuminate\\Validation\\ValidationException;')->toContain('return CreateArticleInput::rules();')->toContain('public function addActionErrors(ValidationException $exception): void')
        ->and($test)->toContain("Livewire::test('blog.create-article')")
        ->and($component.$form.$test)->not->toContain('//')->not->toContain('TODO')->not->toContain('{{--');
});

it('generates no form, save() or comment text without a schema, for classic, MFC and SFC', function (string $flag, string $file) {
    Artisan::call('module:livewire', ['name' => 'Plain', '--module' => 'Blog', $flag => true]);

    $content = File::get($this->module->path.'/'.$file);

    expect($content)->not->toContain('Form')->not->toContain('save(')->not->toContain('//')->not->toContain('{{--')->toContain('<div></div>')
        ->and($this->module->path.'/Livewire/Forms')->not->toBeDirectory();
})->with([
    'classic' => ['--classic', 'resources/views/livewire/plain.blade.php'],
    'mfc' => ['--mfc', 'Livewire/⚡plain/plain.blade.php'],
    'sfc' => ['--sfc', 'Livewire/⚡plain.blade.php'],
]);

it('has no comment text in an inline component, and writes only inside the module', function () {
    Artisan::call('module:livewire', ['name' => 'Tiny', '--module' => 'Blog', '--inline' => true]);

    expect(File::get($this->module->path.'/Livewire/Tiny.php'))->toContain('<div></div>')->not->toContain('{{--')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

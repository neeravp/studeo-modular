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
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function generateAction(object $test, string $name, array $options = []): array
{
    $code = Artisan::call('module:action', ['name' => $name, '--module' => 'Blog', ...$options]);
    $path = $test->module->path.'/Actions/'.(str_ends_with($name, 'Action') ? $name : $name.'Action').'.php';

    return [$code, Artisan::output(), is_file($path) ? File::get($path) : ''];
}

function withPostModel(object $test): void
{
    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]))->toBe(0);
}

it('authorizes a Create Action against the model class when the module has the model', function () {
    withPostModel($this);

    [$code, $output, $action] = generateAction($this, 'CreatePost');

    expect($code)->toBe(0)
        ->and($action)->toContain("Gate::forUser(\$actor)->authorize('create', Post::class);")
        ->toContain("use Studeo\\Blog\\Models\\Post;\n")
        ->toContain('use Studeo\\Blog\\Data\\CreatePostInput;')
        ->toContain('public function __invoke(CreatePostInput $input, Authenticatable $actor): void')
        ->not->toContain('create-post')->not->toContain('App\\')
        ->and($output)->not->toContain('fails closed')->not->toContain('Notice:');
});

it('labels a notice that is not about the schema as a notice', function () {
    [$code, $output] = generateAction($this, 'CreatePost');

    expect($code)->toBe(0)
        ->and($output)->toContain('Notice: ')->toContain('fails closed')
        ->and($output)->not->toContain('Schema warning');
});

it('looks the model up by the name after Create, ignoring the Action folder', function () {
    withPostModel($this);

    [, , $action] = generateAction($this, 'Posts/CreatePost');

    expect($action)->toContain("authorize('create', Post::class);")->toContain("use Studeo\\Blog\\Models\\Post;\n");
});

it('keeps the kebab ability and says so when a Create Action has no model in the module', function () {
    [$code, $output, $action] = generateAction($this, 'CreatePost');

    expect($code)->toBe(0)
        ->and($action)->toContain("authorize('create-post', \$input);")->not->toContain('Post::class')
        ->and($output)->toContain('Notice:')->toContain('Models/Post.php')->toContain('fails closed')->toContain('create-post');
});

it('keeps the kebab ability and says so for every other Action, even one named after a model', function (string $name, string $ability) {
    withPostModel($this);

    [, $output, $action] = generateAction($this, $name);

    expect($action)->toContain("authorize('{$ability}', \$input);")->not->toContain('Post::class')->not->toContain('Models\\Post')
        ->and($output)->toContain('fails closed')->toContain($ability)->toContain('instance');
})->with([
    'update' => ['UpdatePost', 'update-post'],
    'publish' => ['PublishPost', 'publish-post'],
    'not the Create verb' => ['CreatedPost', 'created-post'],
    'only the verb' => ['Create', 'create'],
]);

it('gives a system Action only its Input, no Gate and no notice', function () {
    withPostModel($this);

    [, $output, $action] = generateAction($this, 'CreatePost', ['--system' => true]);

    expect($action)->toContain('public function __invoke(CreatePostInput $input): void')
        ->toContain('#[SystemAction]')
        ->not->toContain('Authenticatable')->not->toContain('$actor')->not->toContain('Gate')->not->toContain('Post::class')
        ->and($output)->not->toContain('fails closed');
});

<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

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

it('creates an action with the pair Input and the one-method shape', function () {
    $this->artisan('module:action', ['name' => 'CreatePostAction', '--module' => 'Blog'])
        ->expectsOutput('Action [CreatePostAction] created successfully in module [Blog].')
        ->assertSuccessful();

    $content = File::get($this->blogModule->path.'/Actions/CreatePostAction.php');

    expect($content)
        ->toContain('namespace Studeo\Blog\Actions;')
        ->toContain('use Studeo\Blog\Data\CreatePostInput;')
        ->toContain('final class CreatePostAction')
        ->toContain('public function __invoke(CreatePostInput $input, Authenticatable $actor): void')
        ->toContain("Gate::forUser(\$actor)->authorize('create-post', \$input);")
        ->toContain('DB::transaction(')
        ->not->toContain('TODO')
        ->not->toContain('AsInvokable')
        ->not->toContain('execute(')
        ->not->toContain('function run')
        ->not->toContain('ShouldQueue')
        ->not->toContain('SystemAction');
});

it('normalises the name and appends Action only when it is missing', function (string $given, string $file) {
    $this->artisan('module:action', ['name' => $given, '--module' => 'Blog'])->assertSuccessful();

    $files = array_map(fn ($found) => $found->getRelativePathname(), File::allFiles($this->blogModule->path.'/Actions'));

    expect($files)->toBe([$file]);
})->with([
    'bare verb phrase' => ['CreatePost', 'CreatePostAction.php'],
    'already suffixed' => ['CreatePostAction', 'CreatePostAction.php'],
    'spaced lower case' => ['create post', 'CreatePostAction.php'],
    'snake suffixed' => ['create_post_action', 'CreatePostAction.php'],
    'nested' => ['Posts/CreatePost', 'Posts/CreatePostAction.php'],
    'nested kebab' => ['posts/create-post', 'Posts/CreatePostAction.php'],
]);

it('fails a name that is only Action and writes nothing', function (string $name) {
    $exitCode = Artisan::call('module:action', ['name' => $name, '--module' => 'Blog']);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('only Action')
        ->and(File::allFiles($this->blogModule->path.'/Actions'))->toBe([])
        ->and(File::allFiles($this->blogModule->path.'/Data'))->toBe([]);
})->with(['Action', 'action', 'Posts/Action']);

it('has no --invokable option', function () {
    expect(fn () => $this->artisan('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--invokable' => true])->run())
        ->toThrow(InvalidOptionException::class, '--invokable');

    expect($this->blogModule->path.'/Actions/CreatePostAction.php')->not->toBeFile();
});

it('generates the paired Input when it is missing and reports both files', function () {
    $exitCode = Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Action [CreatePostAction] created')->toContain('Data/CreatePostInput.php')->toContain('Actions/CreatePostAction.php')
        ->and(File::get($this->blogModule->path.'/Data/CreatePostInput.php'))
        ->toContain('final readonly class CreatePostInput extends InputData');
});

it('leaves an existing paired Input untouched', function () {
    $this->artisan('module:dto', ['name' => 'CreatePostInput', '--module' => 'Blog'])->assertSuccessful();
    $path = $this->blogModule->path.'/Data/CreatePostInput.php';
    File::put($path, File::get($path)."\n// hand-written\n");
    $before = File::get($path);

    $this->artisan('module:action', ['name' => 'CreatePost', '--module' => 'Blog'])->assertSuccessful();

    expect(File::get($path))->toBe($before);
});

it('pairs a nested action with the Input at the same relative path', function () {
    $this->artisan('module:action', ['name' => 'Posts/CreatePost', '--module' => 'Blog'])->assertSuccessful();

    expect(File::get($this->blogModule->path.'/Data/Posts/CreatePostInput.php'))->toContain('namespace Studeo\Blog\Data\Posts;')
        ->and(File::get($this->blogModule->path.'/Actions/Posts/CreatePostAction.php'))
        ->toContain('namespace Studeo\Blog\Actions\Posts;')
        ->toContain('use Studeo\Blog\Data\Posts\CreatePostInput;');
});

it('emits #[SystemAction] and no Gate call with --system', function () {
    $this->artisan('module:action', ['name' => 'RecalculateTotals', '--module' => 'Blog', '--system' => true])->assertSuccessful();

    expect(File::get($this->blogModule->path.'/Actions/RecalculateTotalsAction.php'))
        ->toContain('use Studeo\Modular\Support\Attributes\SystemAction;')
        ->toContain('#[SystemAction]')
        ->toContain('callable only from Actions, Listeners and Jobs')
        ->toContain('public function __invoke(RecalculateTotalsInput $input): void')
        ->toContain('DB::transaction(')
        ->not->toContain('Authenticatable')
        ->not->toContain('$actor')
        ->not->toContain('Gate')
        ->not->toContain('authorize')
        ->not->toContain('TODO');
});

it('prompts for the action name when omitted and applies the same rules to it', function () {
    $this->artisan('module:action', ['--module' => 'Blog'])
        ->expectsQuestion('What should the action be named?', 'archive post')
        ->expectsOutput('Action [ArchivePostAction] created successfully in module [Blog].')
        ->assertSuccessful();

    $this->artisan('module:action', ['--module' => 'Blog'])
        ->expectsQuestion('What should the action be named?', 'Action')
        ->expectsOutputToContain('only Action')
        ->assertFailed();
});

it('fails with a clear message when the name is missing and the command is not interactive', function () {
    $exitCode = Artisan::call('module:action', ['--module' => 'Blog', '--no-interaction' => true]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('name is required')
        ->and(File::allFiles($this->blogModule->path.'/Actions'))->toBe([]);
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:action', ['name' => 'DeletePost'])
        ->expectsChoice('Which module should this belong to?', 'Blog', ['Blog', 'Zeta'])
        ->expectsOutput('Action [DeletePostAction] created successfully in module [Blog].')
        ->assertSuccessful();
});

it('fails when module does not exist', function () {
    $this->artisan('module:action', ['name' => 'Ghost', '--module' => 'NonExistent'])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when the action already exists, before generating an Input', function () {
    $this->artisan('module:action', ['name' => 'DuplicateAction', '--module' => 'Blog'])->assertSuccessful();
    File::delete($this->blogModule->path.'/Data/DuplicateInput.php');

    $this->artisan('module:action', ['name' => 'DuplicateAction', '--module' => 'Blog'])
        ->expectsOutputToContain('Action [DuplicateAction] already exists in module [Blog].')
        ->assertFailed();

    expect($this->blogModule->path.'/Data/DuplicateInput.php')->not->toBeFile();
});

it('writes only inside the module directory', function () {
    $before = $this->rootAppSnapshot();

    $this->artisan('module:action', ['name' => 'CreatePost', '--module' => 'Blog'])->assertSuccessful();
    $this->artisan('module:action', ['name' => 'Recount', '--module' => 'Blog', '--system' => true])->assertSuccessful();

    expect($this->rootAppSnapshot())->toBe($before);
});

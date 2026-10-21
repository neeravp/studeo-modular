<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

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
    $this->artisan('module:schema', ['name' => 'Article', '--module' => 'Blog', '--fields' => 'title:string,note:string:nullable,author_id:foreignId:context'])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'CreateArticle', '--module' => 'Blog', '--schema' => '@articles', '--action' => 'CreateArticle', '--context' => ['author_id=actor']])->assertSuccessful();
    $this->actionHash = md5_file($this->modulesPath.'/Blog/Actions/CreateArticleAction.php');
    $this->inputHash = md5_file($this->modulesPath.'/Blog/Data/CreateArticleInput.php');
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

/**
 * Boot an application with the real Livewire 4 and the installed Blog module in a fresh process,
 * bind a spy for the Action, open the component by its dot alias, save, and report what the spy saw.
 *
 * @return array{calls: array<int, mixed>, errors: array<string, mixed>, forms: array<int, bool>} whether the original and the moved Form class exist
 */
function saveThrough(object $test, string $alias): array
{
    $test->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();

    $script = <<<'PHP'
        require $argv[1];
        spl_autoload_register(function (string $class) use ($argv): void {
            $file = $argv[2].'/Blog/'.str_replace('\\', '/', substr($class, strlen('Studeo\\Blog\\'))).'.php';
            if (str_starts_with($class, 'Studeo\\Blog\\') && is_file($file)) {
                require $file;
            }
        });
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('modular.root_path', $argv[2]);
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app->register(Livewire\LivewireServiceProvider::class);
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $calls = [];
        $app->bind(Studeo\Blog\Actions\CreateArticleAction::class, function () use (&$calls) { return new class($calls) { public function __construct(private array &$calls) {} public function __invoke($input, $actor) { $this->calls[] = [$input->toArray(), $actor->getAuthIdentifier()]; } }; });
        $testable = Livewire\Livewire::actingAs(new Illuminate\Auth\GenericUser(['id' => 7]))->test($argv[3])->set('form.title', 't')->set('form.note', '')->call('save');
        echo json_encode(['calls' => $calls, 'errors' => $testable->errors()->toArray(), 'forms' => array_map(fn ($class) => class_exists($class), ['Studeo\\Blog\\Livewire\\Forms\\CreateArticleForm', 'Studeo\\Blog\\Livewire\\Forms\\Posts\\CreateForm'])]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->modulesPath, $alias]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('still saves through the Action after a rename and then a folder move, under the new dot alias', function () {
    $this->artisan('module:livewire:move', ['name' => 'create-article', 'new-name' => 'PublishArticle', '--module' => 'Blog', '--force' => true])->assertSuccessful();
    $this->artisan('module:livewire:move', ['name' => 'publish-article', 'new-name' => 'Posts/Create', '--module' => 'Blog', '--force' => true])->assertSuccessful();

    $result = saveThrough($this, 'blog.posts.create');

    expect($result['errors'])->toBe([])
        ->and($result['calls'])->toBe([[['title' => 't', 'author_id' => 7, 'note' => null], 7]])
        ->and($result['forms'])->toBe([false, true])
        ->and(md5_file($this->modulesPath.'/Blog/Actions/CreateArticleAction.php'))->toBe($this->actionHash)
        ->and(md5_file($this->modulesPath.'/Blog/Data/CreateArticleInput.php'))->toBe($this->inputHash);
});

it('still saves after a move that leaves a shared Form where it was', function () {
    File::put($this->modulesPath.'/Blog/Other.php', "<?php\n\nnamespace Studeo\\Blog;\n\nclass Other extends \\Studeo\\Blog\\Livewire\\Forms\\CreateArticleForm\n{\n}\n");
    $this->artisan('module:livewire:move', ['name' => 'create-article', 'new-name' => 'Posts/Create', '--module' => 'Blog', '--force' => true])->assertSuccessful();

    $result = saveThrough($this, 'blog.posts.create');

    expect($result['errors'])->toBe([])
        ->and($result['calls'])->toBe([[['title' => 't', 'author_id' => 7, 'note' => null], 7]])
        ->and($result['forms'])->toBe([true, false]);
});

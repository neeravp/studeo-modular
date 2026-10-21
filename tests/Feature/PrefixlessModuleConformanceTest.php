<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->composerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->composerPath) ? File::get($this->composerPath) : null;
    File::put($this->composerPath, json_encode(['name' => 'laravel/laravel', 'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    config(['modular.root_namespace' => null]);
    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
    $this->rootAppBefore = $this->rootAppSnapshot();
    $this->policy = $this->rootModulesPath.'/Blog/Policies/PostPolicy.php';

    foreach ([
        ['module:model', ['name' => 'Post']],
        ['module:policy', ['name' => 'PostPolicy', '--model' => 'Post']],
        ['module:action', ['name' => 'CreatePost']],
    ] as [$command, $arguments]) {
        expect(Artisan::call($command, ['--module' => 'Blog', ...$arguments]))->toBe(0, Artisan::output());
    }

    File::put($this->policy, preg_replace('/(public function create\(Authenticatable \$user\): bool\s*\{\s*return )false;/', '$1true;', File::get($this->policy), 1, $count));
    expect($count)->toBe(1);

    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    $this->originalRootComposer !== null ? File::put($this->composerPath, $this->originalRootComposer) : File::delete($this->composerPath);
});

/**
 * Boots the installed prefix-less module in a fresh process whose autoloader is built from the
 * root composer.json alone, migrates it on SQLite and runs the checks.
 *
 * @return array<string, mixed>
 */
function prefixlessRun(object $test): array
{
    $script = <<<'PHP'
        require $argv[1];
        $composer = json_decode(file_get_contents($argv[2]), true);
        spl_autoload_register(function (string $class) use ($composer, $argv): void {
            $prefixes = array_filter(array_keys($composer['autoload']['psr-4']), fn ($prefix) => str_starts_with($class, $prefix));
            usort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));
            $file = $prefixes === [] ? null : dirname($argv[2]).'/'.rtrim($composer['autoload']['psr-4'][$prefixes[0]], '/').'/'.str_replace('\\', '/', substr($class, strlen($prefixes[0]))).'.php';
            if ($file !== null && is_file($file)) {
                require $file;
            }
        });
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('modular.root_path', $argv[3]);
        $app['config']->set('modular.root_namespace', null);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $app->boot();
        foreach (glob($argv[3].'/Blog/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        $factory = Blog\Models\Post::factory();
        $factory->create();
        $afterFactory = Blog\Models\Post::count();
        $seedExit = Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Blog\Database\Seeders\PostSeeder', '--force' => true]);
        $gate = $app->make(Illuminate\Contracts\Auth\Access\Gate::class);
        $user = new Illuminate\Auth\GenericUser(['id' => 1]);
        (new Blog\Actions\CreatePostAction)(Blog\Data\CreatePostInput::fromValidated([]), $user);
        echo json_encode([
            'provider' => $app->getProvider('Blog\\BlogServiceProvider') === null ? null : 'Blog\\BlogServiceProvider',
            'factory' => get_class($factory),
            'afterFactory' => $afterFactory,
            'seedExit' => $seedExit,
            'afterSeed' => Blog\Models\Post::count(),
            'policy' => get_class($gate->getPolicyFor(Blog\Models\Post::class)),
            'granted' => $gate->forUser($user)->allows('create', Blog\Models\Post::class),
            'afterAction' => Blog\Models\Post::count(),
            'appClasses' => array_values(array_filter(get_declared_classes(), fn ($class) => str_starts_with($class, 'App\\'))),
        ]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->composerPath, $test->rootModulesPath]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function rootPsr4(object $test): array
{
    return json_decode(File::get($test->composerPath), true)['autoload']['psr-4'];
}

it('writes a prefix-less manifest and exactly three PSR-4 entries', function () {
    expect(json_decode(File::get($this->rootModulesPath.'/Blog/module.json'), true))->toBe(['name' => 'Blog', 'namespace' => 'Blog'])
        ->and(rootPsr4($this))->toBe([
            'App\\' => 'app/',
            'Blog\\' => 'modules/Blog/',
            'Blog\\Database\\Factories\\' => 'modules/Blog/database/factories/',
            'Blog\\Database\\Seeders\\' => 'modules/Blog/database/seeders/',
        ]);
});

it('boots, creates rows through the factory, seeds, and runs the Create Action through the real Gate', function () {
    $result = prefixlessRun($this);

    expect($result['provider'])->toBe('Blog\\BlogServiceProvider')
        ->and($result['factory'])->toBe('Blog\\Database\\Factories\\PostFactory')
        ->and($result['afterFactory'])->toBe(1)
        ->and($result['seedExit'])->toBe(0)
        ->and($result['afterSeed'])->toBe(11)
        ->and($result['policy'])->toBe('Blog\\Policies\\PostPolicy')
        ->and($result['granted'])->toBeTrue()
        ->and($result['appClasses'])->toBe([])
        ->and(File::get($this->rootModulesPath.'/Blog/Models/Post.php'))->toContain("namespace Blog\\Models;\n")->not->toContain('Studeo');
});

it('uninstalls by removing exactly the three PSR-4 entries and touches nothing else in the root app', function () {
    $this->artisan('module:uninstall', ['module' => 'Blog'])->assertSuccessful();

    expect(rootPsr4($this))->toBe(['App\\' => 'app/'])
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

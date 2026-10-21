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

    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    $this->originalRootComposer !== null ? File::put($this->composerPath, $this->originalRootComposer) : File::delete($this->composerPath);
});

/**
 * Runs a snippet in a fresh process against SQLite, with the module's migrations run and the
 * autoloader built from the root composer.json alone, as composer would after install; the
 * snippet echoes JSON.
 *
 * @return array<string, mixed>
 */
function modelConformanceRun(object $test, string $snippet): array
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        foreach (glob($argv[3].'/Blog/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script."\n".$snippet, dirname(__DIR__, 2).'/vendor/autoload.php', $test->composerPath, $test->rootModulesPath]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('generates a model whose factory creates rows and whose seeder runs through db:seed', function () {
    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog']))->toBe(0);
    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();

    $result = modelConformanceRun($this, <<<'PHP'
        $factory = Studeo\Blog\Models\Post::factory();
        $factory->create();
        $afterFactory = Studeo\Blog\Models\Post::count();
        $exit = Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Studeo\Blog\Database\Seeders\PostSeeder', '--force' => true]);
        echo json_encode([
            'factory' => get_class($factory),
            'afterFactory' => $afterFactory,
            'seedExit' => $exit,
            'seederFile' => (new ReflectionClass('Studeo\Blog\Database\Seeders\PostSeeder'))->getFileName(),
            'afterSeed' => Studeo\Blog\Models\Post::count(),
        ]);
        PHP);

    expect($result['factory'])->toBe('Studeo\\Blog\\Database\\Factories\\PostFactory')
        ->and($result['afterFactory'])->toBe(1)
        ->and($result['seedExit'])->toBe(0)
        ->and($result['seederFile'])->toEndWith('/Blog/database/seeders/PostSeeder.php')
        ->and($result['afterSeed'])->toBe(11)
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('generates a --no-factory model with no factory reference and a seeder that runs as a no-op', function () {
    expect(Artisan::call('module:model', ['name' => 'Note', '--module' => 'Blog', '--no-factory' => true]))->toBe(0);
    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();

    expect(File::get($this->rootModulesPath.'/Blog/Models/Note.php'))
        ->not->toContain('HasFactory')->not->toContain('Factories')->not->toContain('newFactory')
        ->and(File::get($this->rootModulesPath.'/Blog/database/seeders/NoteSeeder.php'))
        ->not->toContain('Models\\Note')->not->toContain('factory');

    $result = modelConformanceRun($this, <<<'PHP'
        Studeo\Blog\Models\Note::create([]);
        $exit = Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Studeo\Blog\Database\Seeders\NoteSeeder', '--force' => true]);
        echo json_encode([
            'usesHasFactory' => in_array(Illuminate\Database\Eloquent\Factories\HasFactory::class, class_uses_recursive(Studeo\Blog\Models\Note::class), true),
            'seedExit' => $exit,
            'seederFile' => (new ReflectionClass('Studeo\Blog\Database\Seeders\NoteSeeder'))->getFileName(),
            'afterSeed' => Studeo\Blog\Models\Note::count(),
        ]);
        PHP);

    expect($result['usesHasFactory'])->toBeFalse()
        ->and($result['seedExit'])->toBe(0)
        ->and($result['seederFile'])->toEndWith('/Blog/database/seeders/NoteSeeder.php')
        ->and($result['afterSeed'])->toBe(1)
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('wires a --no-factory model to a factory that already exists in the module', function () {
    expect(Artisan::call('module:factory', ['name' => 'PostFactory', '--module' => 'Blog']))->toBe(0)
        ->and(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-factory' => true]))->toBe(0);
    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();

    $result = modelConformanceRun($this, <<<'PHP'
        Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Studeo\Blog\Database\Seeders\PostSeeder', '--force' => true]);
        echo json_encode([
            'factory' => get_class(Studeo\Blog\Models\Post::factory()),
            'afterSeed' => Studeo\Blog\Models\Post::count(),
        ]);
        PHP);

    expect($result['factory'])->toBe('Studeo\\Blog\\Database\\Factories\\PostFactory')
        ->and($result['afterSeed'])->toBe(10)
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('reads an imported enum cast and its fully qualified spelling alike in module:schema:check', function () {
    expect(Artisan::call('module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => 'draft,published']))->toBe(0)
        ->and(Artisan::call('module:enum', ['name' => 'PostKind', '--module' => 'Blog', '--cases' => 'news,essay']))->toBe(0)
        ->and(Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'status:enum(PostStatus)']))->toBe(0)
        ->and(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => '@posts']))->toBe(0);

    $model = $this->rootModulesPath.'/Blog/Models/Post.php';
    expect(File::get($model))->toContain("'status' => PostStatus::class,");

    File::put($model, str_replace("'status' => PostStatus::class,", "'status' => \\Studeo\\Blog\\Enums\\PostStatus::class,", File::get($model)));
    expect(Artisan::call('module:schema:check', ['name' => 'posts', '--module' => 'Blog']))->toBe(0);

    File::put($model, str_replace('PostStatus::class', 'PostKind::class', File::get($model)));
    expect(Artisan::call('module:schema:check', ['name' => 'posts', '--module' => 'Blog']))->toBe(1)
        ->and(Artisan::output())->toContain('Models/Post.php casts [status] differs: schema PostStatus::class; file PostKind::class');
});

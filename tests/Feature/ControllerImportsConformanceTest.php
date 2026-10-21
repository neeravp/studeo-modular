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
    $this->pintPath = sys_get_temp_dir().'/modular-imports-'.bin2hex(random_bytes(6));

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    File::deleteDirectory($this->pintPath);
    $this->originalRootComposer !== null ? File::put($this->composerPath, $this->originalRootComposer) : File::delete($this->composerPath);
});

/**
 * Generates the controllers with the real command, installs the module, then loads them in a
 * fresh process whose autoloader is built from the root composer.json alone, and reports the
 * parameter types and names of every public method.
 *
 * @param  array<string, array<string, mixed>>  $controllers
 * @return array<string, array<string, array<int, string>>>
 */
function loadedControllers(object $test, array $controllers): array
{
    foreach ($controllers as $name => $options) {
        expect(Artisan::call('module:controller', ['name' => $name, '--module' => 'Blog', ...$options]))->toBe(0);
    }

    $test->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();

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
        $result = [];
        foreach (array_slice($argv, 3) as $class) {
            $result[$class] = [];
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $result[$class][$method->getName()] = array_map(fn ($parameter) => $parameter->getType().' $'.$parameter->getName(), $method->getParameters());
            }
        }
        echo json_encode($result);
        PHP;

    $classes = array_map(fn (string $name): string => 'Studeo\\Blog\\Controllers\\'.$name, array_keys($controllers));
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->composerPath, ...$classes]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return array_combine(array_keys($controllers), array_values(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)));
}

it('loads a resource controller for a model named Request, keeping the model fully qualified', function () {
    expect(Artisan::call('module:model', ['name' => 'Request', '--module' => 'Blog', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]))->toBe(0);

    $loaded = loadedControllers($this, ['ReqController' => ['--resource' => true, '--model' => 'Request']]);
    $model = 'Studeo\\Blog\\Models\\Request $request';
    $http = 'Illuminate\\Http\\Request $httpRequest';

    expect($loaded['ReqController']['store'])->toBe([$http])
        ->and($loaded['ReqController']['show'])->toBe([$model])
        ->and($loaded['ReqController']['update'])->toBe([$http, $model])
        ->and($loaded['ReqController']['destroy'])->toBe([$model])
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);

    $source = File::get($this->rootModulesPath.'/Blog/Controllers/ReqController.php');
    expect($source)->toContain("use Illuminate\\Http\\Request;\n")
        ->and($source)->not->toContain('use Studeo\\Blog\\Models\\Request;');
});

it('keeps the request parameter named $request when no model collides, in every controller kind', function () {
    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]))->toBe(0);

    $loaded = loadedControllers($this, [
        'PostController' => ['--resource' => true, '--model' => 'Post'],
        'FeedController' => ['--api' => true, '--model' => 'Post'],
        'PageController' => ['--resource' => true, '--inertia' => true, '--model' => 'Post'],
        'StatusController' => [],
    ]);
    $request = 'Illuminate\\Http\\Request $request';

    expect($loaded['PostController']['update'])->toBe([$request, 'Studeo\\Blog\\Models\\Post $post'])
        ->and($loaded['FeedController']['store'])->toBe([$request])
        ->and($loaded['PageController']['update'])->toBe([$request, 'Studeo\\Blog\\Models\\Post $post'])
        ->and($loaded['StatusController']['__invoke'])->toBe([$request]);
});

it('generates the colliding controller Pint-clean', function () {
    expect(Artisan::call('module:model', ['name' => 'Request', '--module' => 'Blog', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]))->toBe(0)
        ->and(Artisan::call('module:controller', ['name' => 'ReqController', '--module' => 'Blog', '--resource' => true, '--model' => 'Request']))->toBe(0)
        ->and(Artisan::call('module:controller', ['name' => 'ReqInertiaController', '--module' => 'Blog', '--resource' => true, '--inertia' => true, '--model' => 'Request']))->toBe(0);

    File::ensureDirectoryExists($this->pintPath);
    File::copy($this->rootModulesPath.'/Blog/Controllers/ReqController.php', $this->pintPath.'/ReqController.php');
    File::copy($this->rootModulesPath.'/Blog/Controllers/ReqInertiaController.php', $this->pintPath.'/ReqInertiaController.php');
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/pint', '--test', '--preset=laravel', '--format=json', $this->pintPath], $this->pintPath);
    $process->run();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['files'] ?? [])->toBe([]);
});

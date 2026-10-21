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

    foreach ([
        ['module:model', ['name' => 'Post', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]],
        ['module:controller', ['name' => 'StatusController']],
        ['module:controller', ['name' => 'ArticleController', '--resource' => true]],
        ['module:controller', ['name' => 'PostController', '--resource' => true, '--model' => 'Post']],
        ['module:controller', ['name' => 'FeedController', '--api' => true, '--model' => 'post']],
        ['module:controller', ['name' => 'HealthController', '--standard' => true]],
        ['module:controller', ['name' => 'HomeController', '--inertia' => true]],
        ['module:controller', ['name' => 'PageController', '--resource' => true, '--inertia' => true]],
    ] as [$command, $arguments]) {
        expect(Artisan::call($command, ['--module' => 'Blog', ...$arguments]))->toBe(0);
    }

    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    $this->originalRootComposer !== null ? File::put($this->composerPath, $this->originalRootComposer) : File::delete($this->composerPath);
});

/**
 * Real requests through the router to the generated controllers, in a fresh process whose
 * autoloader is built from the root composer.json alone and whose database holds one post.
 *
 * @return array<string, mixed>
 */
function controllerRun(object $test): array
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
        $app['config']->set('app.debug', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Illuminate\Support\Facades\Schema::create('posts', fn ($table) => [$table->id(), $table->timestamps()]);
        Illuminate\Support\Facades\DB::table('posts')->insert(['id' => 1]);
        $namespace = 'Studeo\\Blog\\Controllers\\';
        $router = $app['router'];
        $router->middleware(Illuminate\Routing\Middleware\SubstituteBindings::class)->group(function () use ($router, $namespace) {
            $router->get('/status', $namespace.'StatusController');
            $router->resource('articles', $namespace.'ArticleController');
            $router->resource('posts', $namespace.'PostController');
            $router->apiResource('feeds', $namespace.'FeedController')->parameters(['feeds' => 'post']);
        });
        $app->register(Inertia\ServiceProvider::class);
        $app['config']->set('inertia.pages.ensure_pages_exist', false);
        $router->get('/home', $namespace.'HomeController');
        $router->resource('pages', $namespace.'PageController');
        $page = function (string $method, string $uri) use ($app) {
            $response = $app->handle(Illuminate\Http\Request::create($uri, $method, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']));

            return ['status' => $response->getStatusCode(), 'inertia' => $response->headers->get('X-Inertia'), 'page' => json_decode($response->getContent(), true)];
        };
        $status = fn (string $method, string $uri) => $app->handle(Illuminate\Http\Request::create($uri, $method))->getStatusCode();
        $reflect = fn (string $name) => new ReflectionClass($namespace.$name.'Controller');
        echo json_encode([
            'status' => $status('GET', '/status'),
            'articles' => array_map($status, ['GET', 'GET', 'POST', 'GET', 'GET', 'PUT', 'DELETE'], ['/articles', '/articles/create', '/articles', '/articles/7', '/articles/7/edit', '/articles/7', '/articles/7']),
            'posts' => array_map($status, ['GET', 'GET', 'PUT', 'DELETE', 'GET'], ['/posts/1', '/posts/1/edit', '/posts/1', '/posts/1', '/posts/99']),
            'feeds' => array_map($status, ['GET', 'POST', 'GET', 'PATCH', 'DELETE', 'GET'], ['/feeds', '/feeds', '/feeds/1', '/feeds/1', '/feeds/1', '/feeds/99']),
            'methods' => array_combine(['Status', 'Article', 'Post', 'Feed', 'Health', 'Home', 'Page'], array_map(fn ($name) => array_map(fn ($m) => $m->getName(), $reflect($name)->getMethods(ReflectionMethod::IS_PUBLIC)), ['Status', 'Article', 'Post', 'Feed', 'Health', 'Home', 'Page'])),
            'parents' => array_map(fn ($name) => $reflect($name)->getParentClass(), ['Status', 'Article', 'Post', 'Feed', 'Health', 'Home', 'Page']),
            'inertia' => ['home' => $page('GET', '/home'), 'index' => $page('GET', '/pages'), 'create' => $page('GET', '/pages/create'), 'show' => $page('GET', '/pages/3'), 'edit' => $page('GET', '/pages/3/edit')],
            'showParameter' => (string) (new ReflectionMethod($namespace.'PostController', 'show'))->getParameters()[0]->getType(),
            'appClasses' => array_values(array_filter(get_declared_classes(), fn ($class) => str_starts_with($class, 'App\\'))),
        ]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->composerPath]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('serves the default and resource controllers through the router, binding the model', function () {
    $result = controllerRun($this);

    expect($result['status'])->toBe(200)
        ->and($result['articles'])->toBe([200, 200, 200, 200, 200, 200, 200])
        ->and($result['posts'])->toBe([200, 200, 200, 200, 404])
        ->and($result['feeds'])->toBe([200, 200, 200, 200, 200, 404])
        ->and($result['showParameter'])->toBe('Studeo\\Blog\\Models\\Post');
});

it('loads every controller with no parent class and no App class present', function () {
    $result = controllerRun($this);

    expect($result['parents'])->each->toBeFalse()
        ->and($result['appClasses'])->toBe([])
        ->and($result['methods']['Status'])->toBe(['__invoke'])
        ->and($result['methods']['Health'])->toBe([])
        ->and($result['methods']['Home'])->toBe(['__invoke'])
        ->and($result['methods']['Article'])->toBe(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
        ->and($result['methods']['Feed'])->toBe(['index', 'store', 'show', 'update', 'destroy'])
        ->and($result['methods']['Page'])->toBe(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
});

it('renders the Inertia pages through the router with the real package', function () {
    $result = controllerRun($this)['inertia'];

    expect(array_column($result, 'status'))->each->toBe(200)
        ->and(array_column($result, 'inertia'))->each->toBe('true')
        ->and(array_map(fn (array $response) => $response['page']['component'], $result))->toBe([
            'home' => 'Blog::Home',
            'index' => 'Blog::Page/Index',
            'create' => 'Blog::Page/Create',
            'show' => 'Blog::Page/Show',
            'edit' => 'Blog::Page/Edit',
        ])
        ->and(array_map(fn (array $response) => $response['page']['props'], $result))->each->toBeEmpty();
});

it('writes only inside the module directory', function () {
    controllerRun($this);

    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

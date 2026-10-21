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
    $this->module = $this->rootModulesPath.'/Blog';

    foreach ([
        ['module:schema', ['name' => 'Post', '--fields' => 'title:string,author_id:foreignId:constrained(users):context']],
        ['module:model', ['name' => 'Post', '--schema' => '@posts', '--no-factory' => true, '--no-seeder' => true]],
        ['module:policy', ['name' => 'PostPolicy', '--model' => 'Post']],
        ['module:action', ['name' => 'CreatePost', '--schema' => '@posts']],
        ['module:controller', ['--action' => 'CreatePost', '--context' => ['author_id=actor'], '--url' => '/posts']],
        ['module:controller', ['name' => 'PostForm', '--inertia' => true]],
    ] as [$command, $arguments]) {
        $code = Artisan::call($command, ['--module' => 'Blog', ...$arguments]);
        $output = Artisan::output();

        expect($code)->toBe(0, $output);
        preg_match('/^Route: (Route::post\(.+\);)$/m', $output, $route) === 1 && $this->route = $route[1];
    }

    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();

    File::put($this->module.'/routes/web.php', str_replace('group(function () {});', "group(function () {\n    ".$this->route."\n    Route::get('/posts/create', \\Studeo\\Blog\\Controllers\\PostFormController::class);\n});", File::get($this->module.'/routes/web.php'), $count));
    expect($count)->toBe(1);

    $action = $this->module.'/Actions/CreatePostAction.php';
    File::put($action, preg_replace('/(\n        \}\);\n    \}\n)/', "\n            DB::table('posts')->insert(['title' => \$input->title, 'author_id' => \$input->authorId]);$1", File::get($action), 1, $count));
    expect($count)->toBe(1);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    $this->originalRootComposer !== null ? File::put($this->composerPath, $this->originalRootComposer) : File::delete($this->composerPath);
});

function allowCreatingPosts(object $test): void
{
    $policy = $test->module.'/Policies/PostPolicy.php';
    File::put($policy, preg_replace('/(public function create\(Authenticatable \$user\): bool\s*\{\s*return )false;/', '$1true;', File::get($policy), 1, $count));

    expect($count)->toBe(1);
}

/**
 * Real requests through the module's own routes file, in a fresh process autoloaded from the root
 * composer.json alone, with the real Inertia middleware in the web group and users 1 and 2.
 *
 * @return array<string, mixed>
 */
function actionControllerRun(object $test): array
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
        foreach (['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false, 'database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'modular.root_path' => $argv[3], 'modular.root_namespace' => 'Studeo', 'inertia.pages.ensure_pages_exist' => false] as $key => $value) {
            $app['config']->set($key, $value);
        }
        $app->register(Inertia\ServiceProvider::class);
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $app->make(Illuminate\Contracts\Http\Kernel::class)->appendMiddlewareToGroup('web', Inertia\Middleware::class);
        $app->boot();
        Illuminate\Support\Facades\Schema::create('users', fn ($table) => $table->id());
        Illuminate\Support\Facades\DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        (require glob($argv[3].'/Blog/database/migrations/*_create_posts_table.php')[0])->up();
        $send = function (string $method, string $uri, array $data, array $server, ?int $user, array $cookies = []) use ($app): array {
            $app['auth']->forgetGuards();
            $user === null || $app['auth']->guard('web')->setUser(new Illuminate\Auth\GenericUser(['id' => $user]));
            $response = $app->handle(Illuminate\Http\Request::create($uri, $method, $data, $cookies, [], $server));
            $jar = [];
            foreach ($response->headers->getCookies() as $cookie) {
                $jar[$cookie->getName()] = $cookie->getValue();
            }

            return ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'inertia' => $response->headers->get('X-Inertia'), 'body' => json_decode((string) $response->getContent(), true), 'cookies' => $jar];
        };
        $form = ['HTTP_REFERER' => 'http://localhost/posts/create'];
        $inertia = $form + ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml'];
        $posts = fn () => Illuminate\Support\Facades\DB::table('posts')->get(['title', 'author_id'])->map(fn ($row) => (array) $row)->all();
        $outcome['guest'] = $send('POST', '/posts', ['title' => 'Hello'], $form, null)['status'];
        $outcome['valid'] = $send('POST', '/posts', ['title' => 'Hello', 'author_id' => 2], $form, 1);
        $outcome['postsAfterValid'] = $posts();
        $outcome['json'] = $send('POST', '/posts', [], $form + ['HTTP_ACCEPT' => 'application/json'], 1);
        $outcome['inertiaPost'] = $send('POST', '/posts', [], $inertia, 1);
        $outcome['inertiaPage'] = $send('GET', '/posts/create', [], $inertia, 1, $outcome['inertiaPost']['cookies']);
        $outcome['posts'] = $posts();
        $controller = new ReflectionClass(Studeo\Blog\Controllers\CreatePostController::class);
        echo json_encode($outcome + [
            'parent' => $controller->getParentClass(),
            'parameters' => array_map(fn ($parameter) => (string) $parameter->getType(), $controller->getMethod('__invoke')->getParameters()),
            'appClasses' => array_values(array_filter(get_declared_classes(), fn ($class) => str_starts_with($class, 'App\\'))),
        ]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->composerPath, $test->rootModulesPath]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('prints the route line that the module routes file then serves', function () {
    expect($this->route)->toBe("Route::post('/posts', \\Studeo\\Blog\\Controllers\\CreatePostController::class);");
});

it('answers 403 while the generated policy denies create, and writes nothing', function () {
    $result = actionControllerRun($this);

    expect($result['valid']['status'])->toBe(403)
        ->and($result['posts'])->toBe([]);
});

it('runs the Action on a valid post and redirects back, the context coming from the actor', function () {
    allowCreatingPosts($this);

    $result = actionControllerRun($this);

    expect($result['valid']['status'])->toBe(302)
        ->and($result['valid']['location'])->toBe('http://localhost/posts/create')
        ->and($result['postsAfterValid'])->toBe([['title' => 'Hello', 'author_id' => 1]]);
});

it('answers 401 to a guest before validating', function () {
    allowCreatingPosts($this);

    expect(actionControllerRun($this)['guest'])->toBe(401);
});

it('answers 422 with the validation errors to a JSON request and writes nothing', function () {
    allowCreatingPosts($this);

    $result = actionControllerRun($this);

    expect($result['json']['status'])->toBe(422)
        ->and($result['json']['body']['errors'])->toBe(['title' => ['The title field is required.']])
        ->and($result['posts'])->toHaveCount(1);
});

it('redirects an invalid Inertia post back, and the next Inertia visit shares the errors as props', function () {
    allowCreatingPosts($this);

    $result = actionControllerRun($this);

    expect($result['inertiaPost']['status'])->toBe(302)
        ->and($result['inertiaPost']['location'])->toBe('http://localhost/posts/create')
        ->and($result['inertiaPage']['status'])->toBe(200)
        ->and($result['inertiaPage']['inertia'])->toBe('true')
        ->and($result['inertiaPage']['body']['component'])->toBe('Blog::PostForm')
        ->and($result['inertiaPage']['body']['props']['errors'])->toBe(['title' => 'The title field is required.'])
        ->and($result['posts'])->toHaveCount(1);
});

it('loads the controller with no parent, the Action injected into __invoke and no App class', function () {
    allowCreatingPosts($this);

    $result = actionControllerRun($this);

    expect($result['parent'])->toBeFalse()
        ->and($result['parameters'])->toBe(['Illuminate\\Http\\Request', 'Studeo\\Blog\\Actions\\CreatePostAction'])
        ->and($result['appClasses'])->toBe([]);
});

it('writes only inside the module directory', function () {
    actionControllerRun($this);

    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

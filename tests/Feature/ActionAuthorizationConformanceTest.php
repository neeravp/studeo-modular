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
    $this->policy = $this->rootModulesPath.'/Blog/Policies/PostPolicy.php';
    $this->output = [];

    foreach ([
        ['module:model', ['name' => 'Post', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]],
        ['module:policy', ['name' => 'PostPolicy', '--model' => 'Post']],
        ['module:action', ['name' => 'CreatePost']],
        ['module:action', ['name' => 'PublishPost']],
        ['module:action', ['name' => 'RecalculateTotals', '--system' => true]],
    ] as [$command, $arguments]) {
        expect(Artisan::call($command, ['--module' => 'Blog', ...$arguments]))->toBe(0);
        $this->output[$arguments['name']] = Artisan::output();
    }

    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    $this->originalRootComposer !== null ? File::put($this->composerPath, $this->originalRootComposer) : File::delete($this->composerPath);
});

/**
 * Boots the installed module in a fresh process, its classes autoloaded from the root
 * composer.json alone, with the real Gate. PostPolicy is swapped for a subclass that records
 * which of its methods the Gate called and returns what the generated method returns.
 *
 * @return array<string, mixed>
 */
function authorizationRun(object $test): array
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
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $app->boot();
        eval('namespace Fixture; class SpyPolicy extends \Studeo\Blog\Policies\PostPolicy { public static array $calls = []; public function create(\Illuminate\Contracts\Auth\Authenticatable $user): bool { self::$calls[] = "create"; return parent::create($user); } }');
        $app->bind(Studeo\Blog\Policies\PostPolicy::class, Fixture\SpyPolicy::class);
        $gate = $app->make(Illuminate\Contracts\Auth\Access\Gate::class);
        $user = new Illuminate\Auth\GenericUser(['id' => 1]);
        $run = function (string $action) use ($app, $user): string {
            $class = 'Studeo\\Blog\\Actions\\'.$action.'Action';
            $arguments = [('Studeo\\Blog\\Data\\'.$action.'Input')::fromValidated([]), $user];
            try {
                (new $class)(...array_slice($arguments, 0, (new ReflectionMethod($class, '__invoke'))->getNumberOfParameters()));

                return 'ran';
            } catch (Illuminate\Auth\Access\AuthorizationException) {
                return 'denied';
            }
        };
        $outcome = ['create' => $run('CreatePost')];
        $outcome['createPolicyCalls'] = Fixture\SpyPolicy::$calls;
        Fixture\SpyPolicy::$calls = [];
        $outcome['publish'] = $run('PublishPost');
        $outcome['publishPolicyCalls'] = Fixture\SpyPolicy::$calls;
        $outcome['system'] = $run('RecalculateTotals');
        echo json_encode($outcome + [
            'abilities' => array_keys($gate->abilities()),
            'policyForPost' => $gate->getPolicyFor(Studeo\Blog\Models\Post::class) instanceof Studeo\Blog\Policies\PostPolicy,
            'systemParameters' => array_map(fn ($p) => $p->getName(), (new ReflectionMethod(Studeo\Blog\Actions\RecalculateTotalsAction::class, '__invoke'))->getParameters()),
            'appClasses' => array_values(array_filter(get_declared_classes(), fn ($class) => str_starts_with($class, 'App\\'))),
        ]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->composerPath, $test->rootModulesPath]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('generates a policy whose methods deny, typed Authenticatable, with no App class', function () {
    $policy = File::get($this->policy);

    expect(substr_count($policy, 'return false;'))->toBe(7)
        ->and($policy)->toContain('use Illuminate\\Contracts\\Auth\\Authenticatable;')
        ->and(substr_count($policy, '(Authenticatable $user'))->toBe(7)
        ->and($policy)->not->toContain('App\\');
});

it('reaches PostPolicy::create from the generated Create Action, which denies as generated', function () {
    $result = authorizationRun($this);

    expect($result['policyForPost'])->toBeTrue()
        ->and($result['abilities'])->toBe([])
        ->and($result['create'])->toBe('denied')
        ->and($result['createPolicyCalls'])->toBe(['create'])
        ->and($result['appClasses'])->toBe([]);
});

it('runs the Create Action once the generated policy allows create', function () {
    File::put($this->policy, preg_replace('/(public function create\(Authenticatable \$user\): bool\s*\{\s*return )false;/', '$1true;', File::get($this->policy), 1, $count));

    $result = authorizationRun($this);

    expect($count)->toBe(1)
        ->and($result['create'])->toBe('ran')
        ->and($result['createPolicyCalls'])->toBe(['create']);
});

it('fails a non-Create Action closed, without consulting the policy, and says so when generated', function () {
    $result = authorizationRun($this);

    expect($result['publish'])->toBe('denied')
        ->and($result['publishPolicyCalls'])->toBe([])
        ->and($this->output['PublishPost'])->toContain('publish-post')->toContain('fails closed')
        ->and($this->output['CreatePost'])->not->toContain('fails closed');
});

it('runs a system Action with only its Input', function () {
    $result = authorizationRun($this);

    expect($result['system'])->toBe('ran')
        ->and($result['systemParameters'])->toBe(['input'])
        ->and($this->output['RecalculateTotals'])->not->toContain('fails closed')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

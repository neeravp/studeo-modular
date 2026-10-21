<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

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

/**
 * Generate an Action and its Input with the real command, give the Input one field,
 * then load both in a fresh PHP process and call the Action with a valid Input and an actor.
 * The Gate and DB in that process are recording stand-ins, so the result shows what the
 * generated body consulted.
 *
 * @return array<string, mixed>
 */
function runGeneratedAction(object $test, array $options): array
{
    $test->artisan('module:action', ['name' => 'CreatePost', '--module' => 'Blog', ...$options])->assertSuccessful();

    $input = $test->module->path.'/Data/CreatePostInput.php';
    File::put($input, str_replace(
        ['__construct() {}', 'return [];'],
        ["__construct(\n        public string \$title,\n    ) {}", "return [\n            'title' => ['required', 'string'],\n        ];"],
        File::get($input),
    ));

    $script = <<<'PHP'
        require $argv[1];
        require $argv[2];
        require $argv[3];
        $app = new Illuminate\Container\Container;
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $app->instance('validator', new Illuminate\Validation\Factory(
            new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en')
        ));
        $calls = ['gate' => 0, 'transactions' => 0];
        $gate = new Illuminate\Auth\Access\Gate($app, fn () => null);
        $gate->define('create-post', function ($user, $input) use (&$calls) {
            $calls['gate']++;

            return $input->title === 'Hello';
        });
        $app->instance(Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        $app->instance('db', new class($calls) {
            public function __construct(private array &$calls) {}
            public function transaction(Closure $callback) { $this->calls['transactions']++; return $callback(); }
        });
        $class = 'Studeo\\Blog\\Actions\\CreatePostAction';
        $params = array_map(fn ($p) => $p->getName(), (new ReflectionMethod($class, '__invoke'))->getParameters());
        $arguments = [Studeo\Blog\Data\CreatePostInput::fromValidated(['title' => 'Hello']), new Illuminate\Auth\GenericUser(['id' => 1])];
        $result = (new $class)(...array_slice($arguments, 0, count($params)));
        echo json_encode([
            'params' => $params,
            'result' => $result,
            'calls' => $calls,
            'attributes' => array_map(fn ($a) => $a->getName(), (new ReflectionClass($class))->getAttributes()),
            'methods' => array_map(fn ($m) => $m->getName(), (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC)),
        ]);
        PHP;

    $process = new Process([
        PHP_BINARY, '-r', $script,
        dirname(__DIR__, 2).'/vendor/autoload.php',
        $input,
        $test->module->path.'/Actions/CreatePostAction.php',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('generates an action that loads, runs and consults the Gate in a subprocess', function () {
    $result = runGeneratedAction($this, []);

    expect($result['result'])->toBeNull()
        ->and($result['params'])->toBe(['input', 'actor'])
        ->and($result['calls'])->toBe(['gate' => 1, 'transactions' => 1])
        ->and($result['attributes'])->toBe([])
        ->and($result['methods'])->toBe(['__invoke']);
});

it('generates a system action that loads and runs without consulting the Gate', function () {
    $result = runGeneratedAction($this, ['--system' => true]);

    expect($result['calls'])->toBe(['gate' => 0, 'transactions' => 1])
        ->and($result['params'])->toBe(['input'])
        ->and($result['attributes'])->toBe(['Studeo\\Modular\\Support\\Attributes\\SystemAction'])
        ->and($result['methods'])->toBe(['__invoke']);
});

it('writes only inside the module directory', function () {
    $before = $this->rootAppSnapshot();

    runGeneratedAction($this, []);

    expect($this->rootAppSnapshot())->toBe($before);
});

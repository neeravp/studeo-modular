<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

const THREE_FIELDS = 'slug:string:unique,author_id:foreignId:constrained(users),handle:string:nullable:unique';

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--no-factory' => true, '--schema' => THREE_FIELDS]);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * The methods of a PHP source with the `if`/`elseif` count of each (closures included), the depth
 * of every `if` inside the method body, and its `&&` and `||` count.
 *
 * @return array<string, array{ifs: int, depths: array<int, int>, logic: int}>
 */
function methodStats(string $source): array
{
    $tokens = token_get_all($source);
    $methods = [];
    $current = null;
    $depth = 0;

    foreach ($tokens as $index => $token) {
        $kind = is_array($token) ? $token[0] : $token;

        if ($kind === T_FUNCTION && $current === null && is_array($tokens[$index + 2] ?? null) && $tokens[$index + 2][0] === T_STRING) {
            $current = ['name' => $tokens[$index + 2][1], 'start' => $depth];
            $methods[$current['name']] = ['ifs' => 0, 'depths' => [], 'logic' => 0];
        }

        $depth += (int) in_array($kind, ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) - (int) ($kind === '}');

        if ($current !== null) {
            $methods[$current['name']]['ifs'] += (int) in_array($kind, [T_IF, T_ELSEIF], true);
            in_array($kind, [T_IF, T_ELSEIF], true) && $methods[$current['name']]['depths'][] = $depth - $current['start'];
            $methods[$current['name']]['logic'] += (int) in_array($kind, [T_BOOLEAN_AND, T_BOOLEAN_OR], true);
        }

        if ($current !== null && $kind === '}' && $depth === $current['start']) {
            $current = null;
        }
    }

    return $methods;
}

it('generates a Create* Action whose __invoke holds no if and whose guards are small private methods', function () {
    Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => THREE_FIELDS]);
    $source = File::get($this->module->path.'/Actions/CreatePostAction.php');
    $stats = methodStats($source);

    expect(array_keys($stats))->toBe(['__invoke', 'ensureSlugIsUnique', 'ensureAuthorIdExists', 'ensureHandleIsUnique'])
        ->and($stats['__invoke'])->toBe(['ifs' => 0, 'depths' => [], 'logic' => 0])
        ->and($stats['ensureSlugIsUnique']['ifs'])->toBe(1)
        ->and($stats['ensureAuthorIdExists']['ifs'])->toBe(1)
        ->and($stats['ensureHandleIsUnique']['ifs'])->toBe(2);

    foreach (array_slice($stats, 1) as $guard) {
        expect($guard['ifs'])->toBeLessThanOrEqual(2)->and($guard['logic'])->toBe(0)->and(array_unique($guard['depths']))->toBe([1]);
    }

    expect($source)->toContain('private function ensureSlugIsUnique(CreatePostInput $input): void')
        ->toContain('private function ensureAuthorIdExists(CreatePostInput $input): void')
        ->toContain('private function ensureHandleIsUnique(CreatePostInput $input): void')
        ->and(strpos($source, 'ensureSlugIsUnique($input);'))->toBeLessThan(strpos($source, 'ensureAuthorIdExists($input);'))
        ->and(strpos($source, 'ensureAuthorIdExists($input);'))->toBeLessThan(strpos($source, 'ensureHandleIsUnique($input);'))
        ->and(strpos($source, 'public function __invoke'))->toBeLessThan(strpos($source, 'private function'));
});

it('gives a field that is both unique and a foreign key both guards', function () {
    Artisan::call('module:action', ['name' => 'CreateProfile', '--module' => 'Blog', '--schema' => 'user_id:foreignId:unique:constrained(users)']);
    $stats = methodStats(File::get($this->module->path.'/Actions/CreateProfileAction.php'));

    expect(array_keys($stats))->toBe(['__invoke', 'ensureUserIdIsUnique', 'ensureUserIdExists']);
});

it('keeps an Action with no checks on an empty transaction and adds no guard', function () {
    Artisan::call('module:action', ['name' => 'CreateThing', '--module' => 'Blog', '--schema' => 'title:string']);
    $source = File::get($this->module->path.'/Actions/CreateThingAction.php');

    expect($source)->toContain('DB::transaction(function (): void {});')->not->toContain('private function')->not->toContain('ValidationException');
});

it('uses the guard shape on the Livewire, Vue and controller schema paths', function (string $command, array $options, string $action) {
    fakeInertiaVueInstalled();
    expect(Artisan::call($command, ['--module' => 'Blog', '--schema' => THREE_FIELDS, ...$options]))->toBe(0, Artisan::output());

    $source = File::get($this->module->path."/Actions/{$action}.php");

    expect($source)->toContain('private function ensureSlugIsUnique(')->toContain('private function ensureAuthorIdExists(')->toContain('private function ensureHandleIsUnique(')
        ->and(methodStats($source)['__invoke']['ifs'])->toBe(0);
})->with([
    'livewire' => ['module:livewire', ['name' => 'Note', '--action' => 'CreatePost'], 'CreatePostAction'],
    'vue' => ['module:vue', ['name' => 'Create', '--form' => true, '--action' => 'CreatePost', '--url' => '/vue'], 'CreatePostAction'],
    'controller' => ['module:controller', ['name' => 'StorePostController', '--action' => 'CreatePost'], 'CreatePostAction'],
]);

it('raises ValidationException keyed by the column from a guard, passes nulls and succeeds when valid', function () {
    Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => THREE_FIELDS]);

    $script = <<<'PHP'
        require $argv[1];
        foreach (['Data/CreatePostInput.php', 'Actions/CreatePostAction.php'] as $file) {
            require $argv[2].'/'.$file;
        }
        $capsule = new Illuminate\Database\Capsule\Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $app = new Illuminate\Container\Container;
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance('db.schema', $capsule->schema());
        $app->instance('validator', new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en')));
        $gate = new Illuminate\Auth\Access\Gate($app, fn () => null);
        $gate->define('create', fn () => true);
        $app->instance(Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        $capsule->schema()->create('users', fn ($t) => $t->id());
        $capsule->table('users')->insert(['id' => 1]);
        (require glob($argv[2].'/database/migrations/*_create_posts_table.php')[0])->up();
        $run = function (array $payload) {
            try {
                $input = Studeo\Blog\Data\CreatePostInput::fromValidated(['slug' => 'a', 'author_id' => 1, 'handle' => null, ...$payload]);
                (new Studeo\Blog\Actions\CreatePostAction)($input, new Illuminate\Auth\GenericUser(['id' => 1]));
            } catch (Illuminate\Validation\ValidationException $exception) {
                return array_keys($exception->errors());
            }

            return 'ok';
        };
        $capsule->table('posts')->insert(['slug' => 'taken', 'author_id' => 1, 'handle' => 'used']);
        echo json_encode([
            'valid' => $run([]),
            'unique' => $run(['slug' => 'taken']),
            'foreign' => $run(['slug' => 'b', 'author_id' => 99]),
            'nullPasses' => $run(['slug' => 'c', 'handle' => null]),
            'nullableUnique' => $run(['slug' => 'd', 'handle' => 'used']),
        ]);
        PHP;
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $this->module->path]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'valid' => 'ok',
        'unique' => ['slug'],
        'foreign' => ['author_id'],
        'nullPasses' => 'ok',
        'nullableUnique' => ['handle'],
    ]);
});

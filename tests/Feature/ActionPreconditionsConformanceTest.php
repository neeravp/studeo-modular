<?php

use Illuminate\Support\Facades\Artisan;
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

    Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'slug:string:unique,title:string,author_id:foreignId:constrained(users),category_id:foreignId:constrained,editor_id:foreignId:nullable:constrained(users),handle:string:nullable:unique']);
    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--no-factory' => true, '--schema' => '@posts']);
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function actionFor(string $name, array $options = []): string
{
    Artisan::call('module:action', ['name' => $name, '--module' => 'Blog', '--schema' => '@posts', ...$options]);

    return Artisan::output();
}

/**
 * @return array<string, mixed>
 */
function runPreconditions(): array
{
    $script = <<<'PHP'
        require $argv[1];
        foreach (['Data/CreatePostInput.php', 'Actions/CreatePostAction.php', 'Data/CreateAuditPostInput.php', 'Actions/CreateAuditPostAction.php'] as $file) {
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
        $calls = 0;
        $gate = new Illuminate\Auth\Access\Gate($app, fn () => null);
        $gate->define('create', function () use (&$calls) { $calls++; return true; });
        $app->instance(Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        $schema = $capsule->schema();
        foreach (['users', 'categories'] as $table) {
            $schema->create($table, fn ($t) => $t->id());
            $capsule->table($table)->insert(['id' => 1]);
        }
        (require glob($argv[2].'/database/migrations/*_create_posts_table.php')[0])->up();
        $run = function (string $action, array $payload) use ($app) {
            $input = 'Studeo\\Blog\\Data\\'.str_replace('Action', 'Input', $action);
            try {
                $class = 'Studeo\\Blog\\Actions\\'.$action;
                $arguments = [$input::fromValidated(['slug' => 'a', 'title' => 't', 'author_id' => 1, 'category_id' => 1, 'editor_id' => null, 'handle' => null, ...$payload]), new Illuminate\Auth\GenericUser(['id' => 1])];
                (new $class)(...array_slice($arguments, 0, (new ReflectionMethod($class, '__invoke'))->getNumberOfParameters()));
            } catch (Illuminate\Validation\ValidationException $exception) {
                return $exception->errors();
            }

            return 'ok';
        };
        $first = $run('CreatePostAction', []);
        $capsule->table('posts')->insert(['slug' => 'a', 'title' => 't', 'author_id' => 1, 'category_id' => 1, 'editor_id' => null, 'handle' => null]);
        $capsule->table('posts')->insert(['slug' => 'b', 'title' => 't', 'author_id' => 1, 'category_id' => 1, 'editor_id' => null, 'handle' => 'taken']);
        echo json_encode([
            'first' => $first,
            'unique' => $run('CreatePostAction', []),
            'foreign' => $run('CreatePostAction', ['slug' => 'c', 'author_id' => 99]),
            'derived' => $run('CreatePostAction', ['slug' => 'c', 'category_id' => 99]),
            'nullableForeign' => $run('CreatePostAction', ['slug' => 'c', 'editor_id' => 99]),
            'nullableUnique' => $run('CreatePostAction', ['slug' => 'c', 'handle' => 'taken']),
            'nullsPass' => $run('CreatePostAction', ['slug' => 'c']),
            'gateAfterDefault' => $calls,
            'systemUnique' => $run('CreateAuditPostAction', []),
            'gateAfterSystem' => $calls,
        ]);
        PHP;
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', test()->module->path]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('generates preconditions that pass, or throw ValidationException keyed by the column', function () {
    actionFor('CreatePost');
    actionFor('CreateAuditPost', ['--system' => true]);

    $result = runPreconditions();

    expect($result['first'])->toBe('ok')
        ->and(array_keys($result['unique']))->toBe(['slug'])
        ->and($result['unique']['slug'])->toBe(['The slug has already been taken.'])
        ->and($result['foreign']['author_id'])->toBe(['The selected author id does not exist.'])
        ->and(array_keys($result['derived']))->toBe(['category_id'])
        ->and(array_keys($result['nullableForeign']))->toBe(['editor_id'])
        ->and(array_keys($result['nullableUnique']))->toBe(['handle'])
        ->and($result['nullsPass'])->toBe('ok');
});

it('consults the Gate for the default Action and not for --system, which keeps its preconditions', function () {
    actionFor('CreatePost');
    actionFor('CreateAuditPost', ['--system' => true]);

    $result = runPreconditions();

    expect($result['gateAfterDefault'])->toBe(7)->and($result['gateAfterSystem'])->toBe(7)->and(array_keys($result['systemUnique']))->toBe(['slug']);
});

it('emits complete checks, with no placeholder text and no App namespace', function () {
    actionFor('CreatePost');
    $content = File::get($this->module->path.'/Actions/CreatePostAction.php');

    expect($content)->not->toContain('TODO')->not->toContain('App\\')->not->toContain('//')
        ->toContain('use Illuminate\\Validation\\ValidationException;')
        ->toContain("DB::table('categories')->where('id', \$input->categoryId)->exists()")
        ->toContain('if ($input->editorId === null) {')
        ->toContain("if (! DB::table('users')->where('id', \$input->editorId)->exists()) {")
        ->toContain('if ($input->handle === null) {')
        ->toContain("if (DB::table('posts')->where('handle', \$input->handle)->exists()) {")
        ->not->toContain('&&');
});

it('skips a field the existing Input lacks, with a notice, and leaves the Input alone', function () {
    Artisan::call('module:dto', ['name' => 'CreatePostInput', '--module' => 'Blog', '--schema' => 'slug:string,title:string']);
    $input = $this->module->path.'/Data/CreatePostInput.php';
    $hash = md5_file($input);

    $output = actionFor('CreatePost');
    $content = File::get($this->module->path.'/Actions/CreatePostAction.php');

    expect(md5_file($input))->toBe($hash)
        ->and($output)->toContain('Skipped')->toContain('author_id')->toContain('handle')->not->toContain('[slug]')
        ->and($content)->toContain("'slug' => 'The slug has already been taken.'")->not->toContain('author_id')->not->toContain('handle');
});

it('emits no preconditions for a name that is not Create*, and says so', function () {
    $output = actionFor('PublishPost');
    $content = File::get($this->module->path.'/Actions/PublishPostAction.php');

    expect($output)->toContain('only generated for Create* Actions')
        ->and($content)->toContain('DB::transaction(function (): void {});')->not->toContain('ValidationException')
        ->and($this->module->path.'/Data/PublishPostInput.php')->toBeFile();
});

it('prints the issues and writes nothing for an invalid schema', function () {
    $before = collect(File::allFiles($this->module->path))->map->getPathname()->sort()->values()->all();

    $output = actionFor('CreatePost', ['--schema' => 'slug:strng']);

    expect($output)->toContain('Invalid schema:')
        ->and(collect(File::allFiles($this->module->path))->map->getPathname()->sort()->values()->all())->toBe($before);
});

it('writes only inside the module', function () {
    actionFor('CreatePost');

    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

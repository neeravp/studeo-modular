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
    $this->writeEnumFixture($this->module, 'PostStatus');

    Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'slug:string(60):unique,title:string:default(Untitled),body:text:nullable,price:decimal(8,2):default(12.50),rating:float:nullable,views:integer:default(0),is_live:boolean:default(false),status:enum(PostStatus):default(draft),published_at:datetime:nullable,meta:json:nullable,uuid:uuid:nullable,author_id:foreignId:context,secret:string:guarded,password:string,author:belongsTo(User)']);
    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--no-factory' => true, '--schema' => '@posts']);

    $this->input = $this->module->path.'/Data/PostInput.php';
    $this->output = $this->module->path.'/Data/PostData.php';
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function makeDtos(): void
{
    test()->artisan('module:dto', ['name' => 'PostInput', '--module' => 'Blog', '--schema' => '@posts'])->assertSuccessful();
    test()->artisan('module:dto', ['name' => 'PostData', '--module' => 'Blog', '--schema' => '@posts'])->assertSuccessful();
}

/**
 * Run a snippet in a fresh PHP process with the module's enum, model and DTOs loaded, and an
 * in-memory SQLite database with the posts migration applied.
 *
 * @return array<string, mixed>
 */
function dtoRun(string $snippet): array
{
    $module = test()->module->path;
    $bootstrap = <<<'PHP'
        require $argv[1];
        foreach (['Enums/PostStatus.php', 'Models/Post.php', 'Data/PostInput.php', 'Data/PostData.php'] as $file) {
            require $argv[2].'/'.$file;
        }
        $capsule = new Illuminate\Database\Capsule\Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $app = new Illuminate\Container\Container;
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance('db.schema', $capsule->schema());
        $app->instance('date', new Illuminate\Support\DateFactory);
        $app->instance('validator', new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en')));
        (require glob($argv[2].'/database/migrations/*_create_posts_table.php')[0])->up();
        PHP;

    $process = new Process([PHP_BINARY, '-r', $bootstrap."\n".$snippet, dirname(__DIR__, 2).'/vendor/autoload.php', $module]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

const GOOD_PAYLOAD = "['slug' => 'hello', 'password' => 'secret123', 'title' => 'Hi', 'price' => '9.99', 'views' => '3', 'is_live' => '1', 'status' => 'published', 'author_id' => 99, 'unknown' => 'x']";

it('generates an Input whose required parameters come first and whose rules skip the context field', function () {
    makeDtos();

    $result = dtoRun(<<<'PHP'
        $class = 'Studeo\Blog\Data\PostInput';
        $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();
        $firstOptional = array_key_first(array_filter($parameters, fn ($parameter) => $parameter->isOptional()));
        $violations = array_map(fn ($parameter) => $parameter->getName(), array_filter($parameters, fn ($parameter, $index) => $index > $firstOptional && ! $parameter->isOptional(), ARRAY_FILTER_USE_BOTH));
        echo json_encode([
            'parent' => get_parent_class($class),
            'protected' => (new ReflectionMethod($class, '__construct'))->isProtected(),
            'order' => array_map(fn ($parameter) => $parameter->getName(), $parameters),
            'types' => array_map(fn ($parameter) => (string) $parameter->getType(), $parameters),
            'violations' => $violations,
            'context' => array_map(fn ($parameter) => $parameter->getName(), array_filter($parameters, fn ($parameter) => $parameter->getAttributes(Studeo\Modular\Support\Attributes\Context::class) !== [])),
            'ruleKeys' => array_keys($class::rules()),
            'statusDefault' => (new ReflectionParameter([$class, '__construct'], 'status'))->getDefaultValue() === Studeo\Blog\Enums\PostStatus::Draft,
            'defaults' => array_combine(['title', 'price', 'views', 'isLive', 'body'], array_map(fn ($name) => (new ReflectionParameter([$class, '__construct'], $name))->getDefaultValue(), ['title', 'price', 'views', 'isLive', 'body'])),
        ]);
        PHP);

    expect($result['parent'])->toBe('Studeo\\Modular\\Support\\InputData')
        ->and($result['protected'])->toBeTrue()
        ->and($result['order'])->toBe(['slug', 'authorId', 'password', 'title', 'body', 'price', 'rating', 'views', 'isLive', 'status', 'publishedAt', 'meta', 'uuid'])
        ->and($result['violations'])->toBe([])
        ->and(array_values($result['context']))->toBe(['authorId'])
        ->and($result['ruleKeys'])->toBe(['slug', 'title', 'body', 'price', 'rating', 'views', 'is_live', 'status', 'published_at', 'meta', 'uuid', 'password'])
        ->and($result['statusDefault'])->toBeTrue()
        ->and($result['defaults'])->toBe(['title' => 'Untitled', 'price' => '12.50', 'views' => 0, 'isLive' => false, 'body' => null]);
});

it('types the Input from the field mapping', function () {
    makeDtos();

    $types = dtoRun(<<<'PHP'
        $parameters = (new ReflectionMethod('Studeo\Blog\Data\PostInput', '__construct'))->getParameters();
        echo json_encode(array_combine(array_map(fn ($p) => $p->getName(), $parameters), array_map(fn ($p) => (string) $p->getType(), $parameters)));
        PHP);

    expect($types)->toMatchArray([
        'slug' => 'string', 'authorId' => 'int', 'price' => 'string', 'rating' => '?float', 'views' => 'int', 'isLive' => 'bool',
        'status' => 'Studeo\\Blog\\Enums\\PostStatus', 'publishedAt' => '?Carbon\\CarbonImmutable', 'meta' => '?array', 'uuid' => '?string',
    ]);
});

it('hydrates the Input from a good payload, taking the context field only from context', function () {
    makeDtos();

    $result = dtoRun('$class = "Studeo\\\\Blog\\\\Data\\\\PostInput"; $dto = $class::fromValidated('.GOOD_PAYLOAD.", ['author_id' => 5]);\n"
        .'echo json_encode(["array" => $dto->toArray(), "statusIsEnum" => $dto->status instanceof Studeo\Blog\Enums\PostStatus, "price" => $dto->price]);');

    expect($result['array'])->toMatchArray(['slug' => 'hello', 'author_id' => 5, 'title' => 'Hi', 'price' => '9.99', 'views' => 3, 'is_live' => true, 'status' => 'published', 'body' => null])
        ->and($result['statusIsEnum'])->toBeTrue();
});

it('fails the Input when a required field is missing or a value is bad', function () {
    makeDtos();

    $result = dtoRun(<<<'PHP'
        $class = 'Studeo\Blog\Data\PostInput';
        $errors = function (array $payload) use ($class) {
            try {
                $class::fromValidated($payload, ['author_id' => 5]);
            } catch (Illuminate\Validation\ValidationException $exception) {
                return array_keys($exception->errors());
            }

            return [];
        };
        $good = ['slug' => 'hello', 'password' => 'secret123', 'title' => 'Hi', 'price' => '9.99', 'views' => '3', 'is_live' => '1', 'status' => 'published'];
        echo json_encode([
            'missing' => $errors(array_diff_key($good, ['slug' => 1])),
            'bad' => $errors([...$good, 'price' => 'abc', 'status' => 'nope', 'password' => 'short']),
            'noContext' => (function () use ($class, $good) { try { $class::fromValidated($good); } catch (InvalidArgumentException $e) { return $e->getMessage(); } })(),
        ]);
        PHP);

    expect($result['missing'])->toBe(['slug'])
        ->and($result['bad'])->toEqualCanonicalizing(['price', 'status', 'password'])
        ->and($result['noContext'])->toContain('authorId');
});

it('hydrates the Output from a migrated row with the mapped types', function () {
    makeDtos();

    $result = dtoRun(<<<'PHP'
        Illuminate\Database\Capsule\Manager::table('posts')->insert([
            'slug' => 'hello', 'title' => 'Hi', 'body' => null, 'price' => '9.99', 'rating' => 1.5, 'views' => 3, 'is_live' => 1,
            'status' => 'published', 'published_at' => '2026-01-02 03:04:05', 'meta' => '{"a":1}', 'uuid' => null, 'author_id' => 5,
            'secret' => 's', 'password' => 'hash', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $dto = Studeo\Blog\Data\PostData::from(Studeo\Blog\Models\Post::first());
        $properties = array_map(fn ($property) => $property->getName(), (new ReflectionClass($dto))->getProperties());
        echo json_encode([
            'properties' => $properties,
            'types' => array_map(fn ($value) => get_debug_type($value), array_combine($properties, array_map(fn ($name) => $dto->$name, $properties))),
            'price' => $dto->price,
            'array' => $dto->toArray(),
        ]);
        PHP);

    expect(array_keys($result['types']))->toBe(['id', 'slug', 'title', 'body', 'price', 'rating', 'views', 'isLive', 'status', 'publishedAt', 'meta', 'uuid', 'authorId', 'createdAt', 'updatedAt'])
        ->and($result['types'])->toMatchArray([
            'id' => 'int', 'slug' => 'string', 'body' => 'null', 'price' => 'string', 'rating' => 'float', 'views' => 'int', 'isLive' => 'bool',
            'status' => 'Studeo\\Blog\\Enums\\PostStatus', 'publishedAt' => 'Carbon\\CarbonImmutable', 'meta' => 'array', 'authorId' => 'int',
            'createdAt' => 'Carbon\\CarbonImmutable', 'updatedAt' => 'Carbon\\CarbonImmutable',
        ])
        ->and($result['price'])->toBe('9.99')
        ->and($result['array'])->not->toHaveKeys(['password', 'secret', 'author']);
});

it('never puts a hashed or guarded field in the Output, nor a relationship', function () {
    makeDtos();
    $content = File::get($this->output);

    expect($content)->not->toContain('password')->not->toContain('secret')->not->toContain('$author ')
        ->and($content)->toContain('use Studeo\\Modular\\Support\\OutputData;')->toContain('use Carbon\\CarbonImmutable;');
});

it('lets the name suffix decide the kind, whatever the schema holds', function () {
    makeDtos();

    expect(File::get($this->input))->toContain('extends InputData')->not->toContain('OutputData')
        ->and(File::get($this->output))->toContain('extends OutputData');

    $this->artisan('module:dto', ['name' => 'Post', '--module' => 'Blog', '--schema' => '@posts'])->assertFailed();
});

it('prints every issue and writes nothing for an invalid schema', function () {
    $before = collect(File::allFiles($this->module->path))->map->getPathname()->sort()->values()->all();

    Artisan::call('module:dto', ['name' => 'PostInput', '--module' => 'Blog', '--schema' => 'title:strng,body:txt']);
    $output = Artisan::output();

    expect($output)->toContain('Invalid schema:')->toContain('fields.title:')->toContain('fields.body:')
        ->and(collect(File::allFiles($this->module->path))->map->getPathname()->sort()->values()->all())->toBe($before);
});

it('fails clearly and writes nothing when the enum file is missing', function () {
    File::delete($this->module->path.'/Enums/PostStatus.php');

    $exitCode = Artisan::call('module:dto', ['name' => 'PostInput', '--module' => 'Blog', '--schema' => '@posts']);

    expect($exitCode)->toBe(1)->and(Artisan::output())->toContain('PostStatus')->and($this->input)->not->toBeFile();
});

it('writes only inside the module', function () {
    makeDtos();

    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

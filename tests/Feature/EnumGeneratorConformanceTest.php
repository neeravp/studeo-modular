<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\InvalidSchemaException;
use Studeo\Modular\Support\Schema\EnumFileReader;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    Artisan::call('module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => 'draft,in_review,published']);
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function enumRun(string $script, string ...$arguments): array
{
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', test()->module->path, ...$arguments]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('loads in a fresh process as a string-backed enum with the expected cases', function () {
    $result = enumRun(<<<'PHP'
        require $argv[1];
        require $argv[2].'/Enums/PostStatus.php';
        $enum = new ReflectionEnum('Studeo\Blog\Enums\PostStatus');
        echo json_encode([
            'backing' => (string) $enum->getBackingType(),
            'cases' => array_map(fn ($case) => [$case->getName(), $case->getBackingValue()], $enum->getCases()),
            'from' => Studeo\Blog\Enums\PostStatus::from('draft')->name,
            'methods' => array_map(fn ($m) => $m->getName(), $enum->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC)),
        ]);
        PHP);

    expect($result['backing'])->toBe('string')
        ->and($result['cases'])->toBe([['Draft', 'draft'], ['InReview', 'in_review'], ['Published', 'published']])
        ->and($result['from'])->toBe('Draft')
        ->and($result['methods'])->toBe(['cases', 'from', 'tryFrom']);
});

it('is accepted by the enum reader and the schema validator', function () {
    $info = (new EnumFileReader)->read($this->module->path.'/Enums/PostStatus.php');
    $schema = (new ParseSchemaAction)->execute('status:enum(PostStatus):default(in_review)', $this->module);

    expect($info)->toBe(['declared' => true, 'backing' => 'string', 'cases' => ['Draft' => 'draft', 'InReview' => 'in_review', 'Published' => 'published']])
        ->and($schema->fields['status']->default->value)->toBe('in_review')
        ->and(fn () => (new ParseSchemaAction)->execute('status:enum(PostStatus):default(archived)', $this->module))->toThrow(InvalidSchemaException::class);
});

it('works end to end: enum, model, migration, factory row, and the cast returns the enum', function () {
    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => 'title:string,status:enum(PostStatus):default(draft)']);

    $result = enumRun(<<<'PHP'
        require $argv[1];
        $capsule = new Illuminate\Database\Capsule\Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $app = new Illuminate\Container\Container;
        Illuminate\Container\Container::setInstance($app);
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance('db.schema', $capsule->schema());
        $app->instance('date', new Illuminate\Support\DateFactory);
        $app->singleton(Faker\Generator::class, fn () => Faker\Factory::create());
        foreach (['Enums/PostStatus.php', 'Models/Post.php', 'database/factories/PostFactory.php'] as $file) {
            require $argv[2].'/'.$file;
        }
        (require glob($argv[2].'/database/migrations/*_create_posts_table.php')[0])->up();
        Illuminate\Database\Eloquent\Factories\Factory::guessFactoryNamesUsing(fn ($model) => 'Studeo\Blog\Database\Factories\\'.class_basename($model).'Factory');
        $post = Studeo\Blog\Models\Post::factory()->create();
        $again = Studeo\Blog\Models\Post::find($post->id);
        $default = Studeo\Blog\Models\Post::create(['title' => 'x']);
        echo json_encode([
            'isEnum' => $again->status instanceof Studeo\Blog\Enums\PostStatus,
            'stored' => $capsule->table('posts')->where('id', $post->id)->value('status'),
            'default' => Studeo\Blog\Models\Post::find($default->id)->status->name,
        ]);
        PHP);

    expect($result['isEnum'])->toBeTrue()->and($result['stored'])->toBeIn(['draft', 'in_review', 'published'])->and($result['default'])->toBe('Draft')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

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

    config(['auth.providers.users.model' => 'Fixture\\Auth\\Customer']);
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
 * Generate the five foreign key cases through the real commands, then install the module so the
 * root composer.json maps its Database\Factories namespace. Returns the combined command output.
 */
function generateForeignKeyModels(object $test): string
{
    $output = '';
    $runs = [
        ['Category', 'name:string', ['--no-seeder' => true]],
        ['Draft', 'name:string', ['--no-seeder' => true, '--no-factory' => true]],
        ['Post', 'title:string,category_id:foreignId:constrained,author_id:foreignId:constrained(customers),editor_id:foreignId:nullable:constrained(customers),warehouse_id:foreignId,draft_id:foreignId:constrained,author:belongsTo(User):author_id', ['--no-seeder' => true]],
    ];

    foreach ($runs as [$name, $schema, $flags]) {
        expect(Artisan::call('module:model', ['name' => $name, '--module' => 'Blog', '--schema' => $schema, ...$flags]))->toBe(0);
        $output .= Artisan::output();
    }

    $test->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();

    return $output;
}

/**
 * Runs a snippet in a fresh process with SQLite foreign keys enforced and the autoloader built
 * from the root composer.json alone, as composer would after install; the snippet echoes JSON.
 *
 * @return array<string, mixed>
 */
function foreignKeyRun(object $test, string $snippet): array
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
        eval('namespace Fixture\Auth; class Customer extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected $guarded = []; protected static function newFactory() { return CustomerFactory::new(); } } class CustomerFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = Customer::class; public function definition(): array { return ["name" => "customer"]; } }');
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('auth.providers.users.model', 'Fixture\Auth\Customer');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        Illuminate\Support\Facades\Schema::create('customers', fn ($table) => [$table->id(), $table->string('name'), $table->timestamps()]);
        foreach (glob($argv[3].'/Blog/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script."\n".$snippet, dirname(__DIR__, 2).'/vendor/autoload.php', $test->composerPath, $test->rootModulesPath]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('maps the factory namespace in the root composer.json so generated factories autoload', function () {
    generateForeignKeyModels($this);
    $psr4 = json_decode(File::get($this->composerPath), true)['autoload']['psr-4'];

    expect($psr4)->toHaveKey('Studeo\\Blog\\Database\\Factories\\')
        ->and($psr4['Studeo\\Blog\\Database\\Factories\\'])->toEndWith('database/factories/');
});

it('creates a model whose foreign keys come from the right factories, and reports the keys it leaves out', function () {
    $output = generateForeignKeyModels($this);
    $definition = File::get($this->rootModulesPath.'/Blog/database/factories/PostFactory.php');

    expect($definition)->toContain('use Studeo\\Blog\\Models\\Category;')->toContain("'category_id' => Category::factory(),")
        ->toContain("'author_id' => fn () => config('auth.providers.users.model')::factory(),")
        ->toContain("'editor_id' => null,")
        ->not->toContain('warehouse_id')->not->toContain('draft_id')->not->toContain('App\\')
        ->and($output)->toContain('Notice:')->toContain('warehouse_id')->toContain('DraftFactory.php')
        ->and(substr_count($output, 'Notice:'))->toBe(2);

    $result = foreignKeyRun($this, <<<'PHP'
        $draft = Studeo\Blog\Models\Draft::create(['name' => 'd']);
        $post = Studeo\Blog\Models\Post::factory()->create(['warehouse_id' => 7, 'draft_id' => $draft->id]);
        $factory = Studeo\Blog\Models\Category::factory();
        echo json_encode([
            'categoryFactory' => get_class($factory),
            'categoryFactoryFile' => (new ReflectionClass($factory))->getFileName(),
            'postFactory' => get_class(Studeo\Blog\Models\Post::factory()),
            'categories' => Studeo\Blog\Models\Category::count(),
            'customers' => Fixture\Auth\Customer::count(),
            'authorIsCustomer' => get_class($post->author),
            'editor' => $post->editor_id,
            'categoryMatches' => $post->category_id === Studeo\Blog\Models\Category::first()->id,
        ]);
        PHP);

    expect($result['categoryFactory'])->toBe('Studeo\\Blog\\Database\\Factories\\CategoryFactory')
        ->and($result['categoryFactoryFile'])->toEndWith('/Blog/database/factories/CategoryFactory.php')
        ->and($result['postFactory'])->toBe('Studeo\\Blog\\Database\\Factories\\PostFactory')
        ->and($result['categories'])->toBe(1)
        ->and($result['customers'])->toBe(1)
        ->and($result['authorIsCustomer'])->toBe('Fixture\\Auth\\Customer')
        ->and($result['editor'])->toBeNull()
        ->and($result['categoryMatches'])->toBeTrue()
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('does not count a left-out foreign key as drift, and still counts a real difference', function () {
    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'title:string,warehouse_id:foreignId,editor_id:foreignId:nullable:constrained(customers)'])->assertSuccessful();
    $this->artisan('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => '@posts', '--no-seeder' => true])->assertSuccessful();

    $this->artisan('module:schema:check', ['name' => 'posts', '--module' => 'Blog'])->assertExitCode(0);

    $factory = $this->rootModulesPath.'/Blog/database/factories/PostFactory.php';
    File::put($factory, str_replace("'editor_id' => null,", "'editor_id' => null,\n            'extra' => 1,", File::get($factory)));
    $this->artisan('module:schema:check', ['name' => 'posts', '--module' => 'Blog'])->assertExitCode(1);
});

it('refuses a relationship to a model the module does not have and writes nothing', function () {
    $this->artisan('module:model', ['name' => 'Category', '--module' => 'Blog', '--no-seeder' => true])->assertSuccessful();
    $before = File::allFiles($this->rootModulesPath.'/Blog');

    $code = Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => 'title:string,owner_id:foreignId,owner:belongsTo(Owner)', '--no-seeder' => true]);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('Owner')->toContain('Category')
        ->and(File::allFiles($this->rootModulesPath.'/Blog'))->toHaveCount(count($before))
        ->and(File::exists($this->rootModulesPath.'/Blog/Models/Post.php'))->toBeFalse();
});

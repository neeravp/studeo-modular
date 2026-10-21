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

    File::ensureDirectoryExists($this->module->path.'/Enums');
    File::put($this->module->path.'/Enums/PostStatus.php', "<?php\n\nnamespace Studeo\\Blog\\Enums;\n\nenum PostStatus: string\n{\n    case Draft = 'draft';\n    case Published = 'published';\n}\n");

    fakeLivewireInstalled();
    fakeInertiaVueInstalled();

    $this->schema = implode(',', [
        'slug:string', 'title:string:default(Untitled)', 'label:string:default("it\'s")', 'body:text:nullable',
        'views:integer:default(0)', 'big:bigInteger:default(7)', 'is_active:boolean:default(true)',
        'price:decimal(8,2):default(12.50)', 'ratio:float:default(1.5)', 'rate:double:default(0.25)',
        'status:enum(PostStatus):default(draft)', 'note:string:nullable:default(null)',
        'born:date:nullable', 'published_at:datetime:nullable', 'meta:json:nullable',
    ]);
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * Runs a snippet in a fresh PHP process with a booted app on in-memory SQLite and the
 * module's classes autoloadable; the snippet echoes JSON.
 *
 * @return array<string, mixed>
 */
function typesConformanceRun(object $test, string $snippet, array $arguments = []): array
{
    $script = <<<'PHP'
        require $argv[1];
        spl_autoload_register(function (string $class) use ($argv): void {
            if (! preg_match('/^Studeo\\\\(\w+)\\\\(.+)$/', $class, $match)) {
                return;
            }
            $relative = preg_replace(['#^Database/Factories/#', '#^Database/Seeders/#'], ['database/factories/', 'database/seeders/'], str_replace('\\', '/', $match[2]));
            if (is_file($file = $argv[2].'/'.$match[1].'/'.$relative.'.php')) {
                require $file;
            }
        });
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script."\n".$snippet, dirname(__DIR__, 2).'/vendor/autoload.php', $test->rootModulesPath, ...$arguments]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('runs the migration, applies every default, and casts to the declared types', function () {
    $this->artisan('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => $this->schema])->assertSuccessful();
    $migration = File::glob($this->module->path.'/database/migrations/*_create_posts_table.php')[0];

    $result = typesConformanceRun($this, <<<'PHP'
        (require $argv[3])->up();
        $now = '2024-01-02 03:04:05';
        Illuminate\Support\Facades\DB::table('posts')->insert(['slug' => 's', 'born' => '2020-05-06', 'created_at' => $now, 'updated_at' => $now]);
        $post = Studeo\Blog\Models\Post::first();
        $describe = fn ($value) => is_object($value) ? get_class($value) : get_debug_type($value);
        echo json_encode(collect([
            'title', 'label', 'body', 'views', 'big', 'is_active', 'price', 'ratio', 'rate', 'status', 'note', 'born', 'published_at', 'created_at', 'updated_at',
        ])->mapWithKeys(fn ($key) => [$key => [$describe($post->{$key}), $post->{$key} instanceof BackedEnum ? $post->{$key}->value : (is_object($post->{$key}) ? $post->{$key}->toDateString() : $post->{$key})]])->all());
        PHP, [$migration]);

    expect($result)->toBe([
        'title' => ['string', 'Untitled'],
        'label' => ['string', "it's"],
        'body' => ['null', null],
        'views' => ['int', 0],
        'big' => ['int', 7],
        'is_active' => ['bool', true],
        'price' => ['string', '12.50'],
        'ratio' => ['float', 1.5],
        'rate' => ['float', 0.25],
        'status' => ['Studeo\\Blog\\Enums\\PostStatus', 'draft'],
        'note' => ['null', null],
        'born' => ['Carbon\\CarbonImmutable', '2020-05-06'],
        'published_at' => ['null', null],
        'created_at' => ['Carbon\\CarbonImmutable', '2024-01-02'],
        'updated_at' => ['Carbon\\CarbonImmutable', '2024-01-02'],
    ])
        ->and(File::get($migration))->toContain("->default('draft')")->toContain("->default('it\\'s')")->toContain("->default('12.50')")->not->toContain('default(draft)')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('writes the docblock types, immutable casts and timestamp casts into the model', function () {
    $this->artisan('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => $this->schema])->assertSuccessful();

    expect(File::get($this->module->path.'/Models/Post.php'))
        ->toContain('use Carbon\\CarbonImmutable;')
        ->toContain('@property string $price')
        ->toContain('@property ?CarbonImmutable $born')
        ->toContain('@property ?CarbonImmutable $published_at')
        ->toContain('@property ?string $body')
        ->toContain('@property ?CarbonImmutable $created_at')
        ->toContain('@property ?CarbonImmutable $updated_at')
        ->toContain("'price' => 'decimal:2',")
        ->toContain("'born' => 'immutable_date',")
        ->toContain("'published_at' => 'immutable_datetime',")
        ->toContain("'created_at' => 'immutable_datetime',")
        ->toContain("'updated_at' => 'immutable_datetime',")
        ->not->toContain('|null')
        ->not->toContain('Carbon\\Carbon ');
});

it('casts the timestamps of a model with no schema', function () {
    $this->artisan('module:model', ['name' => 'Tag', '--module' => 'Blog', '--no-seeder' => true])->assertSuccessful();

    expect(File::get($this->module->path.'/Models/Tag.php'))
        ->toContain("'created_at' => 'immutable_datetime',")
        ->toContain("'updated_at' => 'immutable_datetime',")
        ->toContain('use Carbon\\CarbonImmutable;')
        ->toContain('@property ?CarbonImmutable $created_at')
        ->not->toContain('return [];');
});

it('generates a factory whose values have the declared types', function () {
    $this->artisan('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => $this->schema])->assertSuccessful();
    $migration = File::glob($this->module->path.'/database/migrations/*_create_posts_table.php')[0];

    $result = typesConformanceRun($this, <<<'PHP'
        (require $argv[3])->up();
        $post = Studeo\Blog\Models\Post::factory()->create();
        $raw = Illuminate\Support\Facades\DB::table('posts')->first();
        echo json_encode([
            'price' => $post->price,
            'priceType' => get_debug_type($post->price),
            'status' => get_class($post->status),
            'born' => get_class($post->born),
            'created' => get_class($post->created_at),
            'rawPrice' => $raw->price,
        ]);
        PHP, [$migration]);

    expect($result['priceType'])->toBe('string')
        ->and($result['price'])->toMatch('/^\d{1,4}\.\d{2}$/')
        ->and($result['status'])->toBe('Studeo\\Blog\\Enums\\PostStatus')
        ->and($result['born'])->toBe('Carbon\\CarbonImmutable')
        ->and($result['created'])->toBe('Carbon\\CarbonImmutable');
});

it('starts a Livewire form from the declared defaults', function () {
    $this->artisan('module:livewire', ['name' => 'PostEditor', '--module' => 'Blog', '--sfc' => true, '--schema' => $this->schema, '--action' => 'CreatePost'])->assertSuccessful();

    $result = typesConformanceRun($this, <<<'PHP'
        $form = new Studeo\Blog\Livewire\Forms\PostEditorForm(new class extends Livewire\Component {}, 'form');
        $reflection = new ReflectionObject($form);
        $state = [];
        foreach (['title', 'label', 'body', 'views', 'big', 'is_active', 'price', 'ratio', 'rate', 'status', 'note'] as $name) {
            $state[$name] = [(string) $reflection->getProperty($name)->getType(), $form->{$name}];
        }
        echo json_encode($state);
        PHP);

    expect($result)->toBe([
        'title' => ['string', 'Untitled'],
        'label' => ['string', "it's"],
        'body' => ['?string', null],
        'views' => ['?int', 0],
        'big' => ['?int', 7],
        'is_active' => ['bool', true],
        'price' => ['?string', '12.50'],
        'ratio' => ['?string', '1.5'],
        'rate' => ['?string', '0.25'],
        'status' => ['string', 'draft'],
        'note' => ['?string', null],
    ]);
});

it('starts a Vue form from the declared defaults', function () {
    $this->artisan('module:vue', ['name' => 'Posts/Create', '--module' => 'Blog', '--schema' => $this->schema, '--action' => 'CreatePost', '--url' => '/posts'])->assertSuccessful();

    expect(File::get($this->module->path.'/resources/js/Pages/Posts/Create.vue'))
        ->toContain("title: 'Untitled',")
        ->toContain("label: 'it\\'s',")
        ->toContain("body: '',")
        ->toContain('views: 0,')
        ->toContain('big: 7,')
        ->toContain('is_active: true,')
        ->toContain("price: '12.50',")
        ->toContain('ratio: 1.5,')
        ->toContain('rate: 0.25,')
        ->toContain("status: 'draft',")
        ->toContain('note: null,');
});

it('fails a bad default at parse time and writes nothing', function () {
    $exitCode = Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => 'views:integer:default(abc)']);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('[views]')->toContain('[integer]')->toContain('[abc]')
        ->and($this->module->path.'/Models/Post.php')->not->toBeFile()
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

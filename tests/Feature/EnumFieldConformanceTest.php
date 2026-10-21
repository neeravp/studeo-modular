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

    // The enum G6 will generate, written by hand: string-backed, in the module's Enums/.
    File::ensureDirectoryExists($this->module->path.'/Enums');
    File::put($this->module->path.'/Enums/PostStatus.php', "<?php\n\nnamespace Studeo\\Blog\\Enums;\n\nenum PostStatus: string\n{\n    case Draft = 'draft';\n    case Published = 'published';\n}\n");

    // Livewire is not a dependency here; treat it as installed so the command does not run composer.
    fakeLivewireInstalled();

    $this->schema = 'title:string,slug:string:unique,author_id:foreignId,status:enum(PostStatus),state:enum(PostStatus):nullable';
    // A foreignId with no model behind it is left out of the factory, so the model run leaves it out too.
    $this->modelSchema = 'title:string,slug:string:unique,status:enum(PostStatus),state:enum(PostStatus):nullable';
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
function enumConformanceRun(object $test, string $snippet, array $arguments = []): array
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

it('generates a model, migration and factory whose enum column, cast and factory work in a subprocess', function () {
    $this->artisan('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => $this->modelSchema])->assertSuccessful();
    $migration = File::glob($this->module->path.'/database/migrations/*_create_posts_table.php')[0];

    $result = enumConformanceRun($this, <<<'PHP'
        // SQLite keeps no length for varchar, so read it from the Blueprint the migration builds.
        $blueprint = null;
        $builder = $app['db']->connection()->getSchemaBuilder();
        Illuminate\Support\Facades\Schema::swap($builder);
        $builder->blueprintResolver(function ($connection, $table, $callback = null) use (&$blueprint) {
            return $blueprint = new Illuminate\Database\Schema\Blueprint($connection, $table, $callback);
        });
        (require $argv[3])->up();
        $declared = collect($blueprint->getColumns())->keyBy('name');
        $columns = collect(Illuminate\Support\Facades\Schema::getColumns('posts'))->keyBy('name');
        $indexes = collect(Illuminate\Support\Facades\Schema::getIndexes('posts'))->pluck('columns')->all();
        $post = Studeo\Blog\Models\Post::factory()->create(['state' => null]);
        $fresh = Studeo\Blog\Models\Post::find($post->id);
        echo json_encode([
            'declaredType' => $declared['status']['type'],
            'declaredLength' => $declared['status']['length'],
            'statusType' => $columns['status']['type'],
            'stateNullable' => $columns['state']['nullable'],
            'indexes' => $indexes,
            'status' => get_debug_type($fresh->status),
            'state' => $fresh->state,
        ]);
        PHP, [$migration]);

    expect($result['declaredType'])->toBe('string')
        ->and($result['declaredLength'])->toBe(30)
        ->and($result['statusType'])->toContain('varchar')
        ->and($result['stateNullable'])->toBeTrue()
        ->and($result['indexes'])->toContain(['status'])->toContain(['state'])
        ->and($result['status'])->toBe('Studeo\\Blog\\Enums\\PostStatus')
        ->and($result['state'])->toBeNull()
        ->and(File::get($migration))->toContain("\$table->string('status', 30)->index();")->not->toContain('->enum(')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('generates an Input from the same schema that validates enums and has no unique or exists', function () {
    $this->artisan('module:dto', ['name' => 'PostInput', '--module' => 'Blog', '--schema' => $this->schema])->assertSuccessful();
    $path = $this->module->path.'/Data/PostInput.php';

    $result = enumConformanceRun($this, <<<'PHP'
        $valid = ['title' => 'T', 'slug' => 's', 'author_id' => 1, 'status' => 'draft'];
        $fails = function (array $data) {
            try {
                Studeo\Blog\Data\PostInput::fromValidated($data);

                return false;
            } catch (Illuminate\Validation\ValidationException) {
                return true;
            }
        };
        echo json_encode([
            'good' => $fails($valid),
            'badStatus' => $fails([...$valid, 'status' => 'archived']),
            'missingStatus' => $fails(array_diff_key($valid, ['status' => 1])),
            'nullState' => $fails([...$valid, 'state' => null]),
            'badState' => $fails([...$valid, 'state' => 'nope']),
        ]);
        PHP);

    expect($result)->toBe(['good' => false, 'badStatus' => true, 'missingStatus' => true, 'nullState' => false, 'badState' => true])
        ->and(File::get($path))->toContain('Rule::enum(\\Studeo\\Blog\\Enums\\PostStatus::class)')->not->toContain('unique')->not->toContain('exists')->not->toContain("'in:")
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('generates a Livewire Form from the same schema whose rules are the Input rules, enums included, with no unique or exists', function () {
    $this->artisan('module:livewire', ['name' => 'PostEditor', '--module' => 'Blog', '--sfc' => true, '--schema' => $this->schema, '--action' => 'StorePost'])->assertSuccessful();
    $input = File::get($this->module->path.'/Data/StorePostInput.php');

    $result = enumConformanceRun($this, <<<'PHP'
        $form = new Studeo\Blog\Livewire\Forms\PostEditorForm(new class extends Livewire\Component {}, 'form');
        $fails = fn (array $data) => Illuminate\Support\Facades\Validator::make($data, $form->rules())->fails();
        $valid = ['title' => 'T', 'slug' => 's', 'author_id' => 1, 'status' => 'draft'];
        echo json_encode([
            'defaultStatus' => $form->status,
            'defaultState' => $form->state,
            'good' => $fails($valid),
            'badStatus' => $fails([...$valid, 'status' => 'archived']),
            'emptyStatus' => $fails([...$valid, 'status' => '']),
        ]);
        PHP);

    expect($result)->toBe(['defaultStatus' => '', 'defaultState' => null, 'good' => false, 'badStatus' => true, 'emptyStatus' => true])
        ->and($input)->toContain('Rule::enum(\\Studeo\\Blog\\Enums\\PostStatus::class)')->not->toContain('unique')->not->toContain('exists')
        ->and(File::get($this->module->path.'/Livewire/Forms/PostEditorForm.php'))->toContain('return StorePostInput::rules();')->not->toContain('Rule::')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('accepts the file form {type: enum, enum: PostStatus}', function () {
    $schemaFile = sys_get_temp_dir().'/modular-enum-'.bin2hex(random_bytes(4)).'.json';
    File::put($schemaFile, json_encode(['table' => 'posts', 'fields' => ['status' => ['type' => 'enum', 'enum' => 'PostStatus']]]));

    $this->artisan('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => $schemaFile])->assertSuccessful();
    File::delete($schemaFile);

    expect(File::get($this->module->path.'/Models/Post.php'))->toContain('use Studeo\\Blog\\Enums\\PostStatus;')->toContain("'status' => PostStatus::class,");
});

it('fails the legacy inline list with the module:enum hint and writes nothing', function () {
    $exitCode = Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => 'status:enum(draft,published)']);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('module:enum PostStatus --cases=draft,published')->toContain('status:enum(PostStatus)')
        ->and($this->module->path.'/Models/Post.php')->not->toBeFile()
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

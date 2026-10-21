<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->rootComposerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->rootComposerPath) ? File::get($this->rootComposerPath) : null;
    File::put($this->rootComposerPath, json_encode(['name' => 'laravel/laravel', 'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
    $this->artisan('module:schema', ['name' => 'Note', '--module' => 'Blog', '--fields' => 'title:string,meta:json:nullable,extra_settings:json'])->assertSuccessful();
    $this->artisan('module:model', ['name' => 'Note', '--module' => 'Blog', '--no-factory' => true, '--no-seeder' => true, '--schema' => '@notes'])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'CreateNote', '--module' => 'Blog', '--schema' => '@notes', '--action' => 'CreateNote'])->assertSuccessful();
    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

/**
 * Boot an application with the real Livewire 4, the installed Blog module and an in-memory SQLite
 * notes table, bind CreateNoteAction to one that records its Input and saves the Note, then run the
 * snippet. The snippet sees $calls, $save (fills the form state and calls save()) and $outcome.
 *
 * @return mixed the decoded JSON the snippet echoed
 */
function jsonFormRun(object $test, string $snippet): mixed
{
    $script = <<<'PHP'
        require $argv[1];
        spl_autoload_register(function (string $class) use ($argv): void {
            $file = $argv[2].'/Blog/'.str_replace('\\', '/', substr($class, strlen('Studeo\\Blog\\'))).'.php';
            if (str_starts_with($class, 'Studeo\\Blog\\') && is_file($file)) {
                require $file;
            }
        });
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('modular.root_path', $argv[2]);
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app->register(Livewire\LivewireServiceProvider::class);
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        (require glob($argv[2].'/Blog/database/migrations/*_create_notes_table.php')[0])->up();
        Illuminate\Support\Facades\Gate::define('create', fn () => true);
        $calls = [];
        $app->bind(Studeo\Blog\Actions\CreateNoteAction::class, function () use (&$calls) { return new class($calls) { public function __construct(private array &$calls) {} public function __invoke($input, $actor) { $this->calls[] = $input->toArray(); Studeo\Blog\Models\Note::create($input->toArray()); } }; });
        $save = function (array $state) {
            $testable = Livewire\Livewire::actingAs(new Illuminate\Auth\GenericUser(['id' => 7]))->test('blog.create-note');
            foreach (['title' => 't', 'meta' => null, 'extra_settings' => '[1, 2]', ...$state] as $key => $value) { $testable->set('form.'.$key, $value); }
            return $testable->call('save');
        };
        $outcome = function (Closure $run) {
            try {
                return ['ok', $run()];
            } catch (Throwable $exception) {
                return [get_class($exception), method_exists($exception, 'errors') ? $exception->errors() : $exception->getMessage()];
            }
        };
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script."\n".$snippet, dirname(__DIR__, 2).'/vendor/autoload.php', $test->modulesPath]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('holds a json field as a string bound to a textarea', function () {
    $result = jsonFormRun($this, <<<'PHP'
        $form = new Studeo\Blog\Livewire\Forms\CreateNoteForm(new class extends Livewire\Component {}, 'form');
        $types = [];
        foreach (['title', 'meta', 'extra_settings'] as $name) { $types[$name] = [(string) (new ReflectionProperty($form, $name))->getType(), $form->{$name}]; }
        echo json_encode(['types' => $types, 'json' => $form::JSON]);
        PHP);
    $blade = File::get($this->modulesPath.'/Blog/Livewire/⚡create-note.blade.php');

    expect($result)->toBe(['types' => ['title' => ['string', ''], 'meta' => ['?string', null], 'extra_settings' => ['string', '']], 'json' => ['extra_settings', 'meta']])
        ->and($blade)->toMatch('/<textarea\s+id="meta"\s+wire:model="form.meta"/')->toMatch('/<textarea\s+id="extra_settings"\s+wire:model="form.extra_settings"/');
});

it('hands valid JSON to the Action as arrays and saves the record', function () {
    $result = jsonFormRun($this, <<<'PHP'
        $saved = $save(['meta' => '{"tags": ["a", "b"], "n": 1}', 'extra_settings' => ' [true, null] '])->assertHasNoErrors();
        $note = Studeo\Blog\Models\Note::query()->sole();
        echo json_encode(['calls' => $calls, 'stored' => [$note->meta, $note->extra_settings], 'reset' => $saved->get('form.extra_settings')]);
        PHP);

    expect($result['calls'])->toBe([['title' => 't', 'extra_settings' => [true, null], 'meta' => ['tags' => ['a', 'b'], 'n' => 1]]])
        ->and($result['stored'])->toBe([['tags' => ['a', 'b'], 'n' => 1], [true, null]])
        ->and($result['reset'])->toBe('');
});

it('reports invalid JSON on the property and never calls the Action', function () {
    $result = jsonFormRun($this, <<<'PHP'
        $nullable = $save(['meta' => '{"tags": [']);
        $required = $save(['extra_settings' => 'not json']);
        echo json_encode(['nullable' => $nullable->errors()->toArray(), 'required' => $required->errors()->toArray(), 'kept' => $nullable->get('form.meta'), 'calls' => $calls, 'rows' => Studeo\Blog\Models\Note::query()->count()]);
        PHP);

    expect($result)->toBe([
        'nullable' => ['form.meta' => ['The meta field must be valid JSON.']],
        'required' => ['form.extra_settings' => ['The extra settings field must be valid JSON.']],
        'kept' => '{"tags": [',
        'calls' => [],
        'rows' => 0,
    ]);
});

it('sends null for an empty nullable json field and reports an empty required one as required', function () {
    $result = jsonFormRun($this, <<<'PHP'
        $save(['meta' => ''])->assertHasNoErrors();
        $save(['meta' => "  \n"])->assertHasNoErrors();
        $required = $save(['extra_settings' => '']);
        $blank = $save(['extra_settings' => '   ']);
        echo json_encode(['metas' => array_column($calls, 'meta'), 'calls' => count($calls), 'required' => $required->errors()->toArray(), 'blank' => $blank->errors()->toArray()]);
        PHP);

    expect($result)->toBe([
        'metas' => [null, null],
        'calls' => 2,
        'required' => ['form.extra_settings' => ['The extra settings field is required.']],
        'blank' => ['form.extra_settings' => ['The extra settings field is required.']],
    ]);
});

it('decodes in toInput too, where invalid JSON is a validation error keyed by the property', function () {
    $result = jsonFormRun($this, <<<'PHP'
        $form = new Studeo\Blog\Livewire\Forms\CreateNoteForm(new class extends Livewire\Component {}, 'form');
        $form->title = 't';
        $form->meta = '{"a": 1}';
        $form->extra_settings = '[0]';
        $valid = $outcome(fn () => $form->toInput()->toArray());
        $form->meta = '{a: 1}';
        echo json_encode(['valid' => $valid, 'invalid' => $outcome(fn () => $form->toInput())]);
        PHP);

    expect($result['valid'])->toBe(['ok', ['title' => 't', 'extra_settings' => [0], 'meta' => ['a' => 1]]])
        ->and($result['invalid'])->toBe(['Illuminate\\Validation\\ValidationException', ['meta' => ['The meta field must be valid JSON.']]]);
});

it('generates a Form without a json field exactly as before', function () {
    $this->artisan('module:schema', ['name' => 'Tag', '--module' => 'Blog', '--fields' => 'label:string'])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'CreateTag', '--module' => 'Blog', '--schema' => '@tags', '--action' => 'CreateTag'])->assertSuccessful();

    expect(File::get($this->modulesPath.'/Blog/Livewire/Forms/CreateTagForm.php'))
        ->toContain('$state = $this->all();')->not->toContain('JSON')->not->toContain('prepareForValidation')->not->toContain('JsonException');
});

it('writes only inside the module directory', function () {
    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

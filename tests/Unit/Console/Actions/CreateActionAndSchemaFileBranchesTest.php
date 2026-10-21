<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateActionAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateSchemaFileAction;
use Studeo\Modular\DataObjects\ActionGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Exceptions\InvalidSchemaException;
use Studeo\Modular\Support\SchemaWarnings;
use Studeo\Modular\Tests\Support\CallLoggingFilesystem;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition(name: 'Blog', namespace: 'Studeo\\Blog', path: $this->rootModulesPath.'/Blog');

    app(CreateModuleAction::class)->execute($this->module);

    $this->logged = function (callable $run): array {
        $files = new CallLoggingFilesystem;
        $files->modulePath = $this->module->path;
        app()->instance(Filesystem::class, $files);
        $run();

        return $files->calls;
    };
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('warns that an Action without a model authorizes against its Input, after the warnings already collected', function () {
    $warnings = app(SchemaWarnings::class);
    $earlier = SchemaIssue::warning('fields.x', 'earlier', 'An earlier warning.');
    $warnings->add($earlier);

    app(CreateActionAction::class)->execute($this->module, 'PublishPost');

    $flushed = $warnings->flush();

    expect($flushed)->toHaveCount(2)
        ->and($flushed[0])->toBe($earlier)
        ->and($flushed[1]->code)->toBe('action_ability')
        ->and($flushed[1]->path)->toBe('actions.PublishPostAction')
        ->and($flushed[1]->message)->toBe('Action [PublishPostAction] authorizes the ability [publish-post] against its Input. That fails closed until the ability is defined, or the Action loads the model and authorizes against the instance.');
});

it('says why a Create Action whose model is missing authorizes against its Input', function () {
    app(CreateActionAction::class)->execute($this->module, 'CreateGhost');

    $flushed = app(SchemaWarnings::class)->flush();

    expect($flushed)->toHaveCount(1)
        ->and($flushed[0]->message)->toStartWith("Action [CreateGhostAction] has no Models/Ghost.php in module [Blog] to authorize 'create' against,");
});

it('adds no warning for a Create Action of a module model, or for a system Action', function () {
    File::ensureDirectoryExists($this->module->path.'/Models');
    File::put($this->module->path.'/Models/Post.php', "<?php\n");

    $created = app(CreateActionAction::class)->execute($this->module, 'CreatePost');
    app(CreateActionAction::class)->execute($this->module, 'Sweep', new ActionGenerationOptions(system: true));

    expect(app(SchemaWarnings::class)->flush())->toBe([])
        ->and(File::get($created->actionPath))->toContain("Gate::forUser(\$actor)->authorize('create', Post::class);")
        ->and(File::get($created->actionPath))->toContain("use Studeo\\Blog\\Models\\Post;\n");
});

it('imports the system attribute, or Authenticatable and Gate, in the order the imports sort', function () {
    $plain = app(CreateActionAction::class)->execute($this->module, 'PublishPost');
    $system = app(CreateActionAction::class)->execute($this->module, 'Sweep', new ActionGenerationOptions(system: true));

    expect(File::get($plain->actionPath))->toContain("use Illuminate\\Contracts\\Auth\\Authenticatable;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Gate;\n")
        ->and(File::get($system->actionPath))->toContain("use Illuminate\\Support\\Facades\\DB;\nuse Studeo\\Blog\\Data\\SweepInput;\nuse Studeo\\Modular\\Support\\Attributes\\SystemAction;\n");
});

it('checks the Action, pairs the Input, then makes the directory, reads the stub and writes the Action', function () {
    $calls = ($this->logged)(fn () => app(CreateActionAction::class)->execute($this->module, 'PublishPost'));
    $actions = array_values(array_filter($calls, fn (string $call): bool => str_contains($call, 'Actions') || str_contains($call, 'action.stub')));

    expect($actions)->toBe([
        'isFile <module>/Actions/PublishPostAction.php false',
        'ensureDirectoryExists <module>/Actions',
        'isFile <stubs>/action.stub true',
        'get <stubs>/action.stub',
        'put <module>/Actions/PublishPostAction.php',
    ])->and(array_search('exists <module>/Data/PublishPostInput.php false', $calls))->toBeLessThan(array_search('ensureDirectoryExists <module>/Actions', $calls));
});

it('generates the Input before the Action when it is missing, and reads an existing one for its fields', function () {
    File::ensureDirectoryExists($this->module->path.'/Models');
    File::put($this->module->path.'/Models/Post.php', "<?php\n");
    $options = new ActionGenerationOptions(schema: 'slug:string:unique');

    $created = ($this->logged)(fn () => app(CreateActionAction::class)->execute($this->module, 'CreatePost', $options));
    File::delete($this->module->path.'/Actions/CreatePostAction.php');
    $existing = ($this->logged)(fn () => app(CreateActionAction::class)->execute($this->module, 'CreatePost', $options));

    expect($created)->toContain('put <module>/Data/CreatePostInput.php')
        ->and(array_search('put <module>/Data/CreatePostInput.php', $created))->toBeLessThan(array_search('put <module>/Actions/CreatePostAction.php', $created))
        ->and($existing)->not->toContain('put <module>/Data/CreatePostInput.php')
        ->and(File::get($this->module->path.'/Actions/CreatePostAction.php'))->toContain("DB::table('posts')");
});

it('scaffolds a schema file with the minimal schema for no fields or blank fields, and from the fields otherwise', function (?string $fields, string $yaml) {
    $path = app(CreateSchemaFileAction::class)->execute($this->module, 'Post', $fields);

    expect($path)->toBe($this->module->path.'/schema/posts.yaml')
        ->and(File::get($path))->toBe($yaml);
})->with([
    'null' => [null, "table: posts\nfields:\n  name: string\n"],
    'empty' => ['', "table: posts\nfields:\n  name: string\n"],
    'whitespace' => ['   ', "table: posts\nfields:\n  name: string\n"],
    'fields' => ['title:string', "table: posts\nfields:\n  title: string\n"],
]);

it('refuses a schema file that would be invalid, one that exists and a missing module, and writes nothing', function () {
    $action = app(CreateSchemaFileAction::class);
    $ghost = new ModuleDefinition(name: 'Ghost', namespace: 'Studeo\\Ghost', path: $this->rootModulesPath.'/Ghost');

    expect(fn () => $action->execute($this->module, 'Post', '0'))->toThrow(InvalidSchemaException::class, 'Invalid schema: 2 problems found.')
        ->and(File::exists($this->module->path.'/schema/posts.yaml'))->toBeFalse()
        ->and(fn () => $action->execute($ghost, 'Post'))->toThrow(RuntimeException::class, 'Ghost');

    $action->execute($this->module, 'Post');

    expect(fn () => $action->execute($this->module, 'Post'))->toThrow(RuntimeException::class, 'Schema [posts] already exists in module [Blog].');
});

it('checks the schema file, validates what it would write, then makes the directory and writes it', function () {
    $calls = ($this->logged)(fn () => app(CreateSchemaFileAction::class)->execute($this->module, 'Post', 'title:string'));

    expect(array_values(array_filter($calls, fn (string $call): bool => str_contains($call, 'schema'))))->toBe([
        'isFile <module>/schema/posts.yaml false',
        'ensureDirectoryExists <module>/schema',
        'put <module>/schema/posts.yaml',
    ]);
});

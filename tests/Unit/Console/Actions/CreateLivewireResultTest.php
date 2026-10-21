<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateDtoAction;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Enums\LivewireComponentType;
use Studeo\Modular\Support\SchemaWarnings;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');

    app(CreateModuleAction::class)->execute($this->blogModule);
    $this->writeEnumFixture($this->blogModule, 'PostStatus');
    $this->create = fn (string $name, ?LivewireComponentOptions $options = null) => app(CreateLivewireComponentAction::class)->execute($this->blogModule, $name, $options);
    $this->relative = fn (array $paths): array => relativeTo($this->blogModule->path, $paths);
    $this->moduleFiles = fn (): array => relativeTo($this->blogModule->path, collect(File::allFiles($this->blogModule->path))->map->getPathname()->sort()->values()->all());
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function livewireFormOptions(array $extra = []): LivewireComponentOptions
{
    return new LivewireComponentOptions(...['type' => LivewireComponentType::Sfc, 'schema' => 'title:string,status:enum(PostStatus)', 'action' => 'CreatePost', ...$extra]);
}

it('lists the layout first, then the Action, Input and Form, then the component and its test', function () {
    $result = ($this->create)('PostList', livewireFormOptions(['page' => true]));

    expect(($this->relative)($result->createdFiles))->toBe([
        'resources/views/layouts/app.blade.php',
        'Actions/CreatePostAction.php',
        'Data/CreatePostInput.php',
        'Livewire/Forms/Pages/PostListForm.php',
        'Livewire/Pages/⚡post-list.blade.php',
        'tests/Feature/Livewire/Pages/PostListTest.php',
    ])->and($result->reusedFiles)->toBe([])->and($result->notices)->toBe([])
        ->and(($this->relative)($result->component->files))->toBe(['Livewire/Pages/⚡post-list.blade.php', 'tests/Feature/Livewire/Pages/PostListTest.php'])
        ->and($result->component->name)->toBe('Pages/post-list');
});

it('names a classic page component with its class name and a single-file one with its kebab name', function () {
    $classic = ($this->create)('PostList', new LivewireComponentOptions(page: true));
    $sfc = ($this->create)('PostCard', new LivewireComponentOptions(type: LivewireComponentType::Sfc, page: true));

    expect($classic->component->name)->toBe('Pages/PostList')->and($sfc->component->name)->toBe('Pages/post-card');
});

it('puts a page under Pages once, whichever case the prefix has', function (string $typed) {
    $result = ($this->create)($typed, new LivewireComponentOptions(page: true));

    expect($result->component->name)->toBe('Pages/Home')
        ->and(($this->relative)($result->createdFiles))->toBe([
            'resources/views/layouts/app.blade.php',
            'Livewire/Pages/Home.php',
            'resources/views/livewire/pages/home.blade.php',
            'tests/Feature/Livewire/Pages/HomeTest.php',
        ]);
})->with(['pages/Home', 'Pages/Home']);

it('reports reused files and a skipped-field notice, and warns in the order schema, then Action', function () {
    $earlier = SchemaIssue::warning('fields.x', 'earlier', 'An earlier warning.');
    app(SchemaWarnings::class)->add($earlier);
    app(CreateDtoAction::class)->execute($this->blogModule, 'CreatePostInput', app(ParseSchemaAction::class)->execute('title:string', $this->blogModule)->forModule('Studeo\\Blog'));

    $files = orderedFilesystem();
    $result = ($this->create)('Posts/Create', livewireFormOptions(['schema' => 'title:string:unique:index,status:enum(PostStatus)']));
    $warnings = array_map(fn (SchemaIssue $issue): string => "{$issue->code} {$issue->path}", app(SchemaWarnings::class)->flush());

    expect(($this->relative)($result->createdFiles))->toBe(['Actions/CreatePostAction.php', 'Livewire/Forms/Posts/CreateForm.php', 'Livewire/Posts/⚡create.blade.php', 'tests/Feature/Livewire/Posts/CreateTest.php'])
        ->and(($this->relative)($result->reusedFiles))->toBe(['Data/CreatePostInput.php'])
        ->and($result->notices)->toBe(['Skipped form field [status]: the existing Input has no property [status].'])
        ->and($warnings)->toBe(['earlier fields.x', 'field_modifier fields.title', 'action_ability actions.CreatePostAction'])
        ->and($files->calls)->toBe(callFixture('create-sfc-form-notices'));
});

it('reuses an existing Action and Input and reports both, in that order', function () {
    ($this->create)('Other', livewireFormOptions());
    $result = ($this->create)('Second', livewireFormOptions());

    expect(($this->relative)($result->createdFiles))->toBe(['Livewire/Forms/SecondForm.php', 'Livewire/⚡second.blade.php', 'tests/Feature/Livewire/SecondTest.php'])
        ->and(($this->relative)($result->reusedFiles))->toBe(['Actions/CreatePostAction.php', 'Data/CreatePostInput.php']);
});

it('uses a parsed schema definition instead of parsing the schema text', function () {
    $definition = app(ParseSchemaAction::class)->execute('title:string', $this->blogModule)->forModule('Studeo\\Blog');

    $files = orderedFilesystem();
    $result = ($this->create)('Posts/Create', new LivewireComponentOptions(type: LivewireComponentType::Sfc, schema: 'this is not a schema', schemaDefinition: $definition, action: 'CreatePost'));

    expect($files->calls)->toBe(callFixture('create-sfc-schema-definition'))->and(File::get("{$this->blogModule->path}/Livewire/Forms/Posts/CreateForm.php"))->toContain('public string $title')
        ->and(($this->relative)($result->createdFiles))->toContain('Livewire/Forms/Posts/CreateForm.php');
});

it('ignores a schema for classic and multi-file components: no Form, Action or Input, no warnings', function (LivewireComponentType $type, array $expected) {
    $result = ($this->create)('PostList', new LivewireComponentOptions(type: $type, schema: 'title:string', action: 'CreatePost'));

    expect(($this->relative)($result->createdFiles))->toBe($expected)
        ->and($result->reusedFiles)->toBe([])->and($result->notices)->toBe([])
        ->and(app(SchemaWarnings::class)->flush())->toBe([])
        ->and(($this->moduleFiles)())->not->toContain('Actions/CreatePostAction.php', 'Data/CreatePostInput.php');
})->with([
    'classic' => [LivewireComponentType::Classic, ['Livewire/PostList.php', 'resources/views/livewire/post-list.blade.php', 'tests/Feature/Livewire/PostListTest.php']],
    'multi-file' => [LivewireComponentType::Mfc, ['Livewire/⚡post-list/post-list.php', 'Livewire/⚡post-list/post-list.blade.php', 'Livewire/⚡post-list/post-list.test.php']],
]);

it('fails a form page before it writes the layout, the Action, the Input or anything else', function (array $extra, bool $formFileExists, string $message) {
    if ($formFileExists) {
        File::ensureDirectoryExists("{$this->blogModule->path}/Livewire/Forms/Pages");
        File::put("{$this->blogModule->path}/Livewire/Forms/Pages/PostListForm.php", "<?php\n");
    }

    $before = ($this->moduleFiles)();

    expect(fn () => ($this->create)('PostList', livewireFormOptions(['page' => true, ...$extra])))->toThrow(Exception::class, $message)
        ->and(($this->moduleFiles)())->toBe($before);
})->with([
    'no action' => [['action' => null], false, 'A schema needs the Action its form saves through.'],
    'bad schema' => [['schema' => 'title:strng'], false, 'Field [title] has an unknown type [strng].'],
    'form file' => [[], true, 'Form [Livewire/Forms/Pages/PostListForm.php] already exists in module [Blog]; nothing was written.'],
]);

<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\LivewireComponentType;
use Studeo\Modular\Exceptions\InvalidSchemaException;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');

    app(CreateModuleAction::class)->execute($this->blogModule);
    $this->writeEnumFixture($this->blogModule, 'PostStatus');
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

const LIVEWIRE_FORM_SCHEMA = 'title:string,status:enum(PostStatus),is_featured:boolean';

/**
 * Each case: the fixture it is compared with, the component name, its options, what exists
 * beforehand, and the failure it ends in.
 *
 * @return array<string, array{string, string, LivewireComponentOptions, string, ?class-string, ?string}>
 */
function livewireCreateCases(): array
{
    $sfc = fn (array $extra = []): LivewireComponentOptions => new LivewireComponentOptions(...['type' => LivewireComponentType::Sfc, ...$extra]);
    $form = ['schema' => LIVEWIRE_FORM_SCHEMA, 'action' => 'CreatePost'];

    return [
        'create-classic' => ['create-classic', 'PostList', new LivewireComponentOptions, 'nothing', null, null],
        'create-classic-inline-no-test' => ['create-classic-inline-no-test', 'PostList', new LivewireComponentOptions(test: false, inline: true), 'nothing', null, null],
        'create-mfc-assets' => ['create-mfc-assets', 'PostList', new LivewireComponentOptions(type: LivewireComponentType::Mfc, assets: true), 'nothing', null, null],
        'create-sfc' => ['create-sfc', 'PostList', $sfc(), 'nothing', null, null],
        'create-sfc-form' => ['create-sfc-form', 'Posts/Create', $sfc($form), 'nothing', null, null],
        'create-sfc-form-reused' => ['create-sfc-form-reused', 'Second', $sfc($form), 'a form component', null, null],
        'create-classic-ignores-schema' => ['create-classic-ignores-schema', 'PostList', new LivewireComponentOptions(...['type' => LivewireComponentType::Classic, ...$form]), 'nothing', null, null],
        'create-mfc-ignores-schema' => ['create-mfc-ignores-schema', 'PostList', new LivewireComponentOptions(...['type' => LivewireComponentType::Mfc, ...$form]), 'nothing', null, null],
        'create-classic-page' => ['create-classic-page', 'PostList', new LivewireComponentOptions(page: true, layout: 'admin', title: 'T'), 'nothing', null, null],
        'create-sfc-page-form' => ['create-sfc-page-form', 'PostList', $sfc(['page' => true, ...$form]), 'nothing', null, null],
        'create-page-lowercase-prefix' => ['create-page-lowercase-prefix', 'pages/Home', new LivewireComponentOptions(page: true), 'nothing', null, null],
        'create-page-uppercase-prefix' => ['create-page-uppercase-prefix', 'Pages/Home', new LivewireComponentOptions(page: true), 'nothing', null, null],
        'create-error-exists' => ['create-error-exists', 'PostList', new LivewireComponentOptions, 'a classic component', RuntimeException::class, 'Livewire component [PostList] already exists in module [Blog].'],
        'create-error-no-action' => ['create-error-no-action', 'Posts/Create', $sfc(['schema' => LIVEWIRE_FORM_SCHEMA]), 'nothing', RuntimeException::class, 'A schema needs the Action its form saves through.'],
        'create-error-bad-schema' => ['create-error-bad-schema', 'Posts/Create', $sfc(['schema' => 'title:strng', 'action' => 'CreatePost']), 'nothing', InvalidSchemaException::class, 'Field [title] has an unknown type [strng].'],
        'create-error-form-exists' => ['create-error-form-exists', 'Posts/Create', $sfc($form), 'a form file', RuntimeException::class, 'Form [Livewire/Forms/Posts/CreateForm.php] already exists in module [Blog]; nothing was written.'],
    ];
}

function prepareLivewireCreate(string $existing, ModuleDefinition $module): void
{
    $form = new LivewireComponentOptions(type: LivewireComponentType::Sfc, schema: LIVEWIRE_FORM_SCHEMA, action: 'CreatePost');

    match ($existing) {
        'a classic component' => app(CreateLivewireComponentAction::class)->execute($module, 'PostList'),
        'a form component' => app(CreateLivewireComponentAction::class)->execute($module, 'Other', $form),
        'a form file' => writeLivewireFormFile($module),
        default => null,
    };
}

function writeLivewireFormFile(ModuleDefinition $module): void
{
    File::ensureDirectoryExists("{$module->path}/Livewire/Forms/Posts");
    File::put("{$module->path}/Livewire/Forms/Posts/CreateForm.php", "<?php\n");
}

it('asks the Filesystem for the same calls, in the same order, for every create variant', function (string $fixture, string $name, LivewireComponentOptions $options, string $existing, ?string $failure, ?string $message) {
    prepareLivewireCreate($existing, $this->blogModule);

    $files = orderedFilesystem();
    $create = fn () => app(CreateLivewireComponentAction::class)->execute($this->blogModule, $name, $options);

    if ($failure === null) {
        $create();
    } else {
        expect($create)->toThrow($failure, $message);
    }

    expect($files->calls)->toBe(callFixture($fixture));
})->with(livewireCreateCases());

it('refuses a module that is not on disk before touching anything else', function () {
    $ghost = new ModuleDefinition('Ghost', 'Studeo\\Ghost', $this->rootModulesPath.'/Ghost');
    $files = orderedFilesystem();

    expect(fn () => app(CreateLivewireComponentAction::class)->execute($ghost, 'PostList'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghost->path}].")
        ->and($files->calls)->toBe(callFixture('create-error-module-missing'));
});

<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\Schema\SchemaTypes;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    $this->writeEnumFixture($this->module, 'PostStatus');

    fakeLivewireInstalled();
    fakeInertiaVueInstalled();

    $fields = collect(SchemaTypes::TYPES)->reject(fn (string $type): bool => $type === 'enum')->map(fn (string $type, int $index): string => "f{$index}:{$type}")->implode(',');
    $this->fields = 'slug:string(60):unique,title:string:default(Untitled),price:decimal(8,2):nullable:default(12.50),status:enum(PostStatus):default(draft),'
        .'note:string:nullable,author_id:foreignId:constrained(users):cascadeOnDelete,author:belongsTo(User),comments:hasMany(Comment),'.$fields;
    File::put($this->module->path.'/Models/Comment.php', '<?php');
    $this->rootAppBefore = $this->rootAppSnapshot();
    $this->schemaPath = $this->module->path.'/schema/posts.yaml';
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function moduleFiles(object $test): array
{
    return array_map(fn ($file) => $file->getPathname(), File::allFiles($test->module->path));
}

it('scaffolds the minimal valid schema without --fields', function () {
    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog'])
        ->expectsOutputToContain('Schema [posts] created successfully in module [Blog].')
        ->assertSuccessful();

    expect(File::get($this->schemaPath))->toBe("table: posts\nfields:\n  name: string\n")
        ->and(File::get($this->schemaPath))->not->toContain('TODO')
        ->and(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => '@posts']))->toBe(0);
});

it('converts --fields to the schema file and the file validates and generates', function () {
    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => $this->fields])->assertSuccessful();

    $yaml = File::get($this->schemaPath);

    expect($yaml)->toStartWith("table: posts\nfields:\n")->toContain("\nrelationships:\n")->not->toContain('TODO')
        ->and(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => '@posts']))->toBe(0)
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('generates the same files from @posts as from the equivalent inline schema', function () {
    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => $this->fields])->assertSuccessful();
    $generated = fn () => collect(['Models/Post.php', 'database/factories/PostFactory.php'])->mapWithKeys(fn (string $file) => [$file => File::get($this->module->path.'/'.$file)])
        ->put('migration', File::get(File::glob($this->module->path.'/database/migrations/*_create_posts_table.php')[0]))->all();

    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => '@posts']);
    $fromFile = $generated();

    File::delete([$this->module->path.'/Models/Post.php', $this->module->path.'/database/factories/PostFactory.php', ...File::glob($this->module->path.'/database/migrations/*_create_posts_table.php')]);
    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => $this->fields]);

    expect($generated())->toBe($fromFile);
});

it('derives the file name from the table the way module:model does', function (string $name, string $file) {
    $this->artisan('module:schema', ['name' => $name, '--module' => 'Blog'])->assertSuccessful();

    expect($this->module->path."/schema/{$file}")->toBeFile();
})->with([
    ['BlogPost', 'blog_posts.yaml'],
    ['Person', 'people.yaml'],
    ['blog post', 'blog_posts.yaml'],
    ['Category', 'categories.yaml'],
]);

it('writes the model name when the table does not imply it', function () {
    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'title:string'])->assertSuccessful();

    expect(File::get($this->schemaPath))->not->toContain('name:');
});

it('refuses to overwrite an existing schema file', function () {
    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'title:string'])->assertSuccessful();
    $before = File::get($this->schemaPath);

    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'other:string'])
        ->expectsOutputToContain('Schema [posts] already exists in module [Blog].')
        ->assertFailed();

    expect(File::get($this->schemaPath))->toBe($before);
});

it('validates what it would write and writes nothing when that fails', function () {
    $exitCode = Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'Bad-Name:string,title:strng,status:enum(draft,published)']);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('Invalid schema: 3 problems found.')->toContain('fields.title:')->toContain('Valid: string, char')
        ->and($this->module->path.'/schema')->not->toBeDirectory()
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('checks the enum against the module before writing', function () {
    $exitCode = Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'status:enum(Missing)']);

    expect($exitCode)->not->toBe(0)->and(Artisan::output())->toContain('Enums/Missing.php')
        ->and($this->schemaPath)->not->toBeFile();
});

it('writes a schema with warnings and keeps the exit code at zero', function () {
    expect(Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'user:belongsTo(User)']))->toBe(0)
        ->and(Artisan::output())->toContain('Notice:')->toContain('user_id')
        ->and($this->schemaPath)->toBeFile();
});

it('asks for the name when it is omitted, and fails without it when not interactive', function () {
    $this->artisan('module:schema', ['--module' => 'Blog'])
        ->expectsQuestion('What is the model name?', 'Post')
        ->expectsOutputToContain('Schema [posts] created successfully')
        ->assertSuccessful();

    $exitCode = Artisan::call('module:schema', ['--module' => 'Blog', '--no-interaction' => true]);

    expect($exitCode)->not->toBe(0)->and(Artisan::output())->toContain('name is required');
});

it('writes only inside the module directory', function () {
    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => $this->fields])->assertSuccessful();

    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore)
        ->and(array_values(array_map(fn (string $file) => str_replace($this->module->path.'/', '', $file), array_filter(moduleFiles($this), fn (string $file) => str_contains($file, '/schema/')))))
        ->toBe(['schema/posts.yaml']);
});

it('accepts @posts in every --schema option', function (string $command, array $arguments) {
    $this->artisan('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'title:string,status:enum(PostStatus),is_featured:boolean'])->assertSuccessful();

    expect(Artisan::call($command, [...$arguments, '--module' => 'Blog', '--schema' => '@posts']))->toBe(0);
})->with([
    'model' => ['module:model', ['name' => 'Post', '--no-seeder' => true]],
    'dto' => ['module:dto', ['name' => 'PostInput']],
    'action' => ['module:action', ['name' => 'CreatePost']],
    'livewire' => ['module:livewire', ['name' => 'PostForm', '--sfc' => true, '--action' => 'CreatePost']],
    'vue' => ['module:vue', ['name' => 'Posts/Create', '--action' => 'CreatePost', '--url' => '/posts']],
]);

it('gives a clear error for a missing @name in every --schema option, and writes nothing', function (string $command, array $arguments) {
    $before = moduleFiles($this);

    $exitCode = Artisan::call($command, [...$arguments, '--module' => 'Blog', '--schema' => '@posts']);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('@posts')->toContain($this->module->path.'/schema/posts.yaml')
        ->and(moduleFiles($this))->toBe($before);
})->with([
    'model' => ['module:model', ['name' => 'Post']],
    'dto' => ['module:dto', ['name' => 'PostInput']],
    'action' => ['module:action', ['name' => 'CreatePost']],
    'livewire' => ['module:livewire', ['name' => 'PostForm', '--sfc' => true, '--action' => 'CreatePost']],
    'vue' => ['module:vue', ['name' => 'Posts/Create']],
]);

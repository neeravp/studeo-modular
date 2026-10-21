<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    $this->writeEnumFixture($this->module, 'PostStatus');

    Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'slug:string:unique,title:string,price:decimal(8,2),status:enum(PostStatus):default(draft),published_at:datetime:nullable,author_id:foreignId']);
    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => '@posts']);

    $this->model = $this->module->path.'/Models/Post.php';
    $this->factory = $this->module->path.'/database/factories/PostFactory.php';
    $this->migration = File::glob($this->module->path.'/database/migrations/*_create_posts_table.php')[0];
    $this->migrationName = 'database/migrations/'.basename($this->migration);
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function runCheck(array $arguments = ['name' => 'Post']): array
{
    $exitCode = Artisan::call('module:schema:check', [...$arguments, '--module' => 'Blog']);

    return [$exitCode, Artisan::output()];
}

function editFile(string $file, string $search, string $replace): void
{
    $content = File::get($file);

    expect($content)->toContain($search);

    File::put($file, str_replace($search, $replace, $content));
}

it('exits 0 and says so right after generation', function () {
    [$exitCode, $output] = runCheck();

    expect($exitCode)->toBe(0)->and($output)->toContain('Schema [posts]: no drift.')->not->toContain(' schema has ')->not->toContain('unverifiable');
});

it('reports each hand edit precisely and exits 1', function () {
    editFile($this->model, "        'slug',\n", '');
    editFile($this->migration, "\$table->string('title');", "\$table->string('title', 100);");
    editFile($this->factory, "'title' => fake()->sentence(),\n", '');

    [$exitCode, $output] = runCheck();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Models/Post.php fillable schema has [slug]; file lacks it')
        ->toContain("{$this->migrationName} columns [title] differs: schema \$table->string('title'); file \$table->string('title', 100)")
        ->toContain('database/factories/PostFactory.php definition schema has [title]; file lacks it')
        ->toContain('Schema [posts]: 3 differences.')
        ->not->toContain('no drift');
});

it('reports names that are only in the file, and changed rendered definitions', function () {
    editFile($this->model, "        'title',\n", "        'title',\n        'secret',\n");
    editFile($this->model, "'price' => 'decimal:2',", "'price' => 'decimal:4',");
    editFile($this->migration, '$table->timestamps();', "\$table->string('extra');\n            \$table->timestamps();");
    editFile($this->factory, "'title' => fake()->sentence(),", "'title' => fake()->sentence(),\n            'bonus' => 1,");

    [$exitCode, $output] = runCheck();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Models/Post.php fillable file has [secret]; schema lacks it')
        ->toContain("Models/Post.php casts [price] differs: schema 'decimal:2'; file 'decimal:4'")
        ->toContain("{$this->migrationName} columns file has [extra]; schema lacks it")
        ->toContain('database/factories/PostFactory.php definition file has [bonus]; schema lacks it');
});

it('groups the lines by file, one file after another', function () {
    editFile($this->model, "        'slug',\n", '');
    editFile($this->factory, "'title' => fake()->sentence(),\n", '');
    editFile($this->migration, "\$table->string('title');", "\$table->string('title', 100);");

    $lines = array_values(array_filter(explode("\n", runCheck()[1]), fn (string $line) => str_contains($line, ' schema has ') || str_contains($line, ' differs: ')));
    $files = array_map(fn (string $line) => explode(' ', $line)[0], $lines);

    expect($files)->toBe(array_values(array_unique($files, SORT_REGULAR)))->and(count(array_unique($files)))->toBe(3);
});

it('still exits 0 after hand-added code that is not a schema section', function () {
    editFile($this->model, '    protected function casts(): array', "    public function isPublished(): bool\n    {\n        return \$this->published_at !== null;\n    }\n\n    protected function casts(): array");
    editFile($this->factory, '    public function definition(): array', "    public function published(): static\n    {\n        return \$this->state(['published_at' => now()]);\n    }\n\n    public function definition(): array");
    File::append($this->module->path.'/database/migrations/2099_01_01_000000_add_x_to_posts_table.php', "<?php\nreturn new class { public function up(): void { Schema::table('posts', function (\$table) { \$table->string('x'); }); } };\n");

    expect(runCheck()[0])->toBe(0);
});

it('exits 2 and prints the issues when the schema is invalid', function () {
    editFile($this->module->path.'/schema/posts.yaml', 'title: string', 'title: strng');

    [$exitCode, $output] = runCheck();

    expect($exitCode)->toBe(2)->and($output)->toContain('Invalid schema:')->toContain('fields.title:')->toContain('Valid: string, char');
});

it('reports a missing file or section as unverifiable and exits 1', function (string $break, string $expected) {
    match ($break) {
        'factory' => File::delete($this->factory),
        'model' => File::delete($this->model),
        'migration' => File::delete($this->migration),
        'fillable' => editFile($this->model, 'protected $fillable', 'protected $notFillable'),
        'casts' => editFile($this->model, 'protected function casts(): array', 'protected function castses(): array'),
        'definition' => editFile($this->factory, 'public function definition(): array', 'public function defined(): array'),
        'columns' => editFile($this->migration, "Schema::create('posts'", "Schema::table('posts'"),
    };

    [$exitCode, $output] = runCheck();

    expect($exitCode)->toBe(1)->and($output)->toContain($expected);
})->with([
    'factory file' => ['factory', 'database/factories/PostFactory.php definition unverifiable'],
    'model file' => ['model', 'Models/Post.php fillable unverifiable'],
    'migration file' => ['migration', 'create_posts_table.php columns unverifiable'],
    'fillable property' => ['fillable', 'Models/Post.php fillable unverifiable'],
    'casts method' => ['casts', 'Models/Post.php casts unverifiable'],
    'definition method' => ['definition', 'database/factories/PostFactory.php definition unverifiable'],
    'create call' => ['columns', 'columns unverifiable'],
]);

it('checks every schema in the module when no name is given', function () {
    Artisan::call('module:schema', ['name' => 'Tag', '--module' => 'Blog', '--fields' => 'label:string']);
    Artisan::call('module:model', ['name' => 'Tag', '--module' => 'Blog', '--no-seeder' => true, '--schema' => '@tags']);

    [$exitCode, $output] = runCheck([]);

    expect($exitCode)->toBe(0)->and($output)->toContain('Schema [posts]: no drift.')->toContain('Schema [tags]: no drift.');

    editFile($this->model, "        'slug',\n", '');

    [$exitCode, $output] = runCheck([]);

    expect($exitCode)->toBe(1)->and($output)->toContain('Models/Post.php fillable schema has [slug]')->toContain('Schema [tags]: no drift.');
});

it('exits 2 when any schema is invalid, even if another drifts', function () {
    Artisan::call('module:schema', ['name' => 'Tag', '--module' => 'Blog', '--fields' => 'label:string']);
    editFile($this->module->path.'/schema/tags.yaml', 'label: string', 'label: strng');
    editFile($this->model, "        'slug',\n", '');

    [$exitCode, $output] = runCheck([]);

    expect($exitCode)->toBe(2)->and($output)->toContain('fields.label:')->toContain('Models/Post.php fillable schema has [slug]');
});

it('says what is wrong when there is nothing to check', function () {
    File::delete($this->module->path.'/schema/posts.yaml');

    [$exitCode, $output] = runCheck([]);
    [$namedExit, $namedOutput] = runCheck(['name' => 'Post']);

    expect($exitCode)->toBe(1)->and($output)->toContain('No schema files')
        ->and($namedExit)->toBe(2)->and($namedOutput)->toContain('schema/posts.yaml');
});

it('keeps warnings out of the exit code', function () {
    File::put($this->module->path.'/schema/posts.yaml', File::get($this->module->path.'/schema/posts.yaml')."relationships:\n  owner: belongsTo(User)\n");

    [$exitCode, $output] = runCheck();

    expect($exitCode)->toBe(0)->and($output)->toContain('Notice:')->toContain('owner_id');
});

it('changes nothing', function () {
    $hashes = fn () => array_map('md5_file', array_map(fn ($file) => $file->getPathname(), File::allFiles($this->module->path)));
    $before = $hashes();

    editFile($this->model, "        'slug',\n", '');
    $edited = $hashes();
    runCheck();

    expect($hashes())->toBe($edited)->and($edited)->not->toBe($before)->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('skips the sections named by --except, neither comparing nor reporting them', function () {
    Artisan::call('module:schema', ['name' => 'Tag', '--module' => 'Blog', '--fields' => 'label:string']);
    Artisan::call('module:model', ['name' => 'Tag', '--module' => 'Blog', '--no-seeder' => true, '--no-factory' => true, '--schema' => '@tags']);

    [$without] = runCheck(['name' => 'Tag']);
    [$exitCode, $output] = runCheck(['name' => 'Tag', '--except' => 'factory']);

    expect($without)->toBe(1)
        ->and($exitCode)->toBe(0)
        ->and($output)->toContain('Schema [tags]: no drift.')->not->toContain('unverifiable');

    editFile($this->model, "        'slug',\n", '');

    [$exitCode, $output] = runCheck(['--except' => 'migration, factory']);

    expect($exitCode)->toBe(1)->and($output)->toContain('Models/Post.php fillable schema has [slug]')->not->toContain('columns');

    [$exitCode, $output] = runCheck(['--except' => 'model']);

    expect($output)->not->toContain('Models/Post.php');
});

it('rejects an unknown --except value and lists the valid ones', function () {
    [$exitCode, $output] = runCheck(['name' => 'Post', '--except' => 'factory,views']);

    expect($exitCode)->toBe(1)->and($output)->toContain('[views]')->toContain('Valid: model, migration, factory');
});

it('warns on stderr, naming every file, when several create migrations exist, and keeps reading the last', function () {
    File::put($this->module->path.'/database/migrations/2000_01_01_000000_create_posts_table.php', '<?php // an older, unrelated file');

    [$exitCode, $output] = runCheck();

    expect($exitCode)->toBe(0)->and($output)->toContain('Notice:')->toContain('2000_01_01_000000_create_posts_table.php')->toContain(basename($this->migration))
        ->and($output)->toContain('Schema [posts]: no drift.');
});

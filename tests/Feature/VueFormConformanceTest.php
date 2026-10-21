<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

/**
 * One entry per field: [state value, TypeScript type, control marker]. Order is the Input's:
 * required parameters first, then the optional ones, each group in schema order.
 */
const VUE_FIELDS = [
    'slug' => ["''", 'string', 'type="text"'],
    'code' => ["''", 'string', 'type="text"'],
    'summary' => ["''", 'string', '<textarea'],
    'intro' => ["''", 'string', '<textarea'],
    'small' => ['null', 'number | null', 'type="number"'],
    'tags' => ["''", 'string', '<textarea'],
    'ref' => ["''", 'string', 'type="text"'],
    'sort_key' => ["''", 'string', 'type="text"'],
    'category_id' => ['null', 'number | null', 'type="number"'],
    'team_uuid' => ["''", 'string', 'type="text"'],
    'owner_ulid' => ["''", 'string', 'type="text"'],
    'body' => ["''", 'string | null', '<textarea'],
    'views' => ['0', 'number | null', 'type="number"'],
    'big' => ['7', 'number | null', 'type="number"'],
    'tiny' => ['null', 'number | null', 'type="number"'],
    'is_active' => ['true', 'boolean', 'type="checkbox"'],
    'price' => ["'12.50'", 'number | string | null', 'type="number" step="any"'],
    'ratio' => ['1.5', 'number | string | null', 'type="number" step="any"'],
    'rate' => ['0.25', 'number | string | null', 'type="number" step="any"'],
    'born' => ["''", 'string | null', 'type="date"'],
    'published_at' => ["''", 'string | null', 'type="datetime-local"'],
    'seen_at' => ["''", 'string | null', 'type="datetime-local"'],
    'meta' => ["''", 'string', '<textarea'],
    'status' => ["'draft'", 'string', 'type="text"'],
    'note' => ['null', 'string | null', 'type="text"'],
];

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    Artisan::call('module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => 'draft,published']);
    Artisan::call('module:schema', ['name' => 'Article', '--module' => 'Blog', '--fields' => implode(',', [
        'slug:string:unique', 'code:char', 'summary:text', 'intro:mediumText', 'body:longText:nullable',
        'small:unsignedSmallInteger', 'tags:jsonb', 'ref:uuid', 'sort_key:ulid',
        'category_id:foreignId:constrained(categories)', 'team_uuid:foreignUuid:constrained(teams)', 'owner_ulid:foreignUlid:constrained(owners)',
        'views:integer:default(0)', 'big:bigInteger:default(7)', 'tiny:tinyInteger:nullable', 'is_active:boolean:default(true)',
        'price:decimal(8,2):default(12.50)', 'ratio:float:default(1.5)', 'rate:double:default(0.25)',
        'born:date:nullable', 'published_at:dateTime:nullable', 'seen_at:timestamp:nullable', 'meta:json:nullable',
        'status:enum(PostStatus):default(draft)', 'note:string:nullable:default(null)', 'author_id:foreignId:constrained(users):context',
    ])]);

    fakeInertiaVueInstalled();

    $exitCode = Artisan::call('module:vue', ['name' => 'Posts/Create', '--module' => 'Blog', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => '/posts']);
    expect($exitCode)->toBe(0, Artisan::output());
    $this->page = File::get($this->module->path.'/resources/js/Pages/Posts/Create.vue');
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('starts the state from the declared defaults, in the Input order, and leaves the context field out', function () {
    $lines = collect(VUE_FIELDS)->map(fn (array $field, string $name): string => "  {$name}: {$field[0]},")->implode("\n");

    expect($this->page)->toContain("const form = useForm<FormState>({\n{$lines}\n})")->not->toContain('author_id');
});

it('declares a typed state from the same field list', function () {
    $lines = collect(VUE_FIELDS)->map(fn (array $field, string $name): string => "  {$name}: {$field[1]}")->implode("\n");

    expect($this->page)->toContain("type FormState = {\n{$lines}\n}")->toContain('<script setup lang="ts">');
});

it('binds a control and an error to every field', function (string $name, array $field) {
    expect($this->page)
        ->toContain("v-model=\"form.{$name}\"")
        ->toContain("v-if=\"form.errors.{$name}\"")
        ->toContain('{{ form.errors.'.$name.' }}')
        ->toContain($field[2]);
})->with(fn () => collect(VUE_FIELDS)->map(fn (array $field, string $name): array => [$name, $field])->all());

it('posts to the given URL and carries no placeholder, comment or instruction', function () {
    expect($this->page)
        ->toContain(".post('/posts')")->toContain('@submit.prevent="submit"')->toContain(':disabled="form.processing"')
        ->not->toContain('//')->not->toContain('<!--')->not->toContain('/*')->not->toContain('TODO')->not->toContain('{{ formState }}')->not->toContain('{{ formFields }}');
});

it('parses every json field before posting, sends nothing when one fails, and posts the parsed values', function () {
    expect($this->page)
        ->toContain("const parseJson = (field: 'tags' | 'meta', nullable: boolean): unknown => {")
        ->toContain("const json = {\n    tags: parseJson('tags', false),\n    meta: parseJson('meta', true),\n  }")
        ->toContain("if (Object.values(json).includes(invalidJson)) {\n    return\n  }")
        ->toContain("form.transform((data) => ({ ...data, ...json })).post('/posts')")
        ->toContain('field is required.')->toContain('field must be valid JSON.')
        ->toMatch('/<textarea\s+id="tags"\s+v-model="form.tags"/')->toMatch('/<textarea\s+id="meta"\s+v-model="form.meta"/')
        ->not->toContain("form.post('/posts')");
});

it('posts a form without json fields directly', function () {
    expect(Artisan::call('module:vue', ['name' => 'Tags/Create', '--module' => 'Blog', '--schema' => 'label:string', '--action' => 'CreateTag', '--url' => '/tags']))->toBe(0)
        ->and(File::get($this->module->path.'/resources/js/Pages/Tags/Create.vue'))
        ->toContain("const submit = () => {\n  form.post('/tags')\n}")->not->toContain('parseJson')->not->toContain('transform');
});

it('generates the Action, its Input and the preconditions, and the Action loads in a fresh process', function () {
    $action = File::get($this->module->path.'/Actions/CreateArticleAction.php');
    $script = 'require $argv[1]; require $argv[2]; require $argv[3]; require $argv[4]; echo json_encode(array_map(fn ($m) => $m->getName(), (new ReflectionClass(Studeo\Blog\Actions\CreateArticleAction::class))->getMethods()));';
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $this->module->path.'/Enums/PostStatus.php', $this->module->path.'/Data/CreateArticleInput.php', $this->module->path.'/Actions/CreateArticleAction.php']);
    $process->run();

    expect($action)->toContain("DB::table('articles')->where('slug'")->toContain("DB::table('categories')")->toContain("DB::table('users')")
        ->and($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput())
        ->and(json_decode($process->getOutput(), true))->toContain('__invoke');
});

it('writes an accepted URL as a plain string literal', function () {
    $exitCode = Artisan::call('module:vue', ['name' => 'Posts/Other', '--module' => 'Blog', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => '/posts-1/new_2.json~']);

    expect($exitCode)->toBe(0)
        ->and(File::get($this->module->path.'/resources/js/Pages/Posts/Other.vue'))->toContain(".post('/posts-1/new_2.json~')");
});

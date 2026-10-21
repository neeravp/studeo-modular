<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

uses(TestCase::class)->group('vue');

const VUE_TOOLCHAIN_PACKAGES = ['vue', '@vue/compiler-sfc', 'typescript', 'vue-tsc', '@inertiajs/vue3'];

const VUE_TOOLCHAIN_MISSING = 'The vue group needs Node 20 or newer on PATH, then `npm install` in the package root.';

/**
 * The generated files under test, keyed by the name they get in the fixture's pages/ folder.
 */
const VUE_GENERATED_FILES = [
    'Create.vue' => 'resources/js/Pages/Posts/Create.vue',
    'Index.vue' => 'resources/js/Pages/Posts/Index.vue',
    'PostCard.vue' => 'resources/js/Components/PostCard.vue',
];

function vueFixture(string $path = ''): string
{
    return rtrim(dirname(__DIR__).'/fixtures/vue/'.$path, '/');
}

function vueNodeBinary(): ?string
{
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        return null;
    }

    $version = new Process([$node, '-p', 'process.versions.node.split(".")[0]']);
    $version->run();

    return (int) trim($version->getOutput()) >= 20 ? $node : null;
}

function vueToolchainInstalled(): bool
{
    return collect(VUE_TOOLCHAIN_PACKAGES)
        ->every(fn (string $package): bool => is_file(dirname(__DIR__, 2).'/node_modules/'.$package.'/package.json'));
}

function vueRun(string $node, array $arguments): Process
{
    $process = new Process([$node, ...$arguments], dirname(__DIR__, 2));
    $process->setTimeout(300);
    $process->run();

    return $process;
}

function vueTsc(string $node, string $project): Process
{
    return vueRun($node, ['node_modules/vue-tsc/bin/vue-tsc.js', '--noEmit', '--pretty', 'false', '-p', vueFixture($project)]);
}

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    $this->node = vueNodeBinary();

    if ($this->node === null || ! vueToolchainInstalled()) {
        $this->markTestSkipped(VUE_TOOLCHAIN_MISSING);
    }

    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
    $module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($module);

    fakeInertiaVueInstalled();

    Artisan::call('module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => 'draft,published']);
    Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => implode(',', [
        'title:string', 'body:text:nullable', 'views:integer:default(0)', 'price:decimal(8,2):nullable',
        'is_active:boolean:default(true)', 'status:enum(PostStatus):default(draft)', 'published_on:date:nullable',
        'meta:json:nullable', 'extra_settings:json', 'author_id:foreignId:constrained(users):context',
    ])]);

    foreach ([
        ['name' => 'Posts/Create', '--schema' => '@posts', '--action' => 'CreatePost', '--url' => '/posts'],
        ['name' => 'Posts/Index', '--page' => true],
        ['name' => 'PostCard'],
    ] as $arguments) {
        expect(Artisan::call('module:vue', ['--module' => 'Blog', ...$arguments]))->toBe(0, Artisan::output());
    }

    File::deleteDirectory(vueFixture('pages'));
    File::ensureDirectoryExists(vueFixture('pages'));

    foreach (VUE_GENERATED_FILES as $name => $path) {
        File::copy($module->path.'/'.$path, vueFixture('pages/'.$name));
    }
});

afterEach(function () {
    File::deleteDirectory(vueFixture('pages'));
    File::deleteDirectory($this->rootModulesPath);
});

it('generates a form page that covers text, number, boolean, enum, date and json fields', function () {
    expect(File::get(vueFixture('pages/Create.vue')))
        ->toContain('body: string | null')->toContain('views: number | null')->toContain('is_active: boolean')
        ->toContain('status: string')->toContain('published_on: string | null')->toContain("meta: string\n")->toContain("extra_settings: string\n")
        ->and(File::get(vueFixture('pages/Index.vue')))->not->toContain('<script');
});

it('compiles every generated SFC with @vue/compiler-sfc without errors', function () {
    $files = array_map(fn (string $name): string => vueFixture('pages/'.$name), array_keys(VUE_GENERATED_FILES));
    $process = vueRun($this->node, [vueFixture('compile-sfc.mjs'), ...$files]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(json_decode($process->getOutput(), true))->toBe(array_fill_keys(array_keys(VUE_GENERATED_FILES), []));
});

it('type-checks the fixture with vue-tsc --noEmit and zero diagnostics', function () {
    $process = vueTsc($this->node, 'tsconfig.json');

    expect(trim($process->getOutput().$process->getErrorOutput()))->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

it('type-checks the useForm calls the generated form relies on against the installed @inertiajs/vue3', function () {
    $process = vueTsc($this->node, 'tsconfig.use-form.json');

    expect(trim($process->getOutput().$process->getErrorOutput()))->toBe('')
        ->and($process->getExitCode())->toBe(0)
        ->and(File::get(vueFixture('use-form.ts')))
        ->toContain('useForm<FormState>(')->toContain("form.post('/posts')")->toContain('form.errors.title')
        ->toContain('form.transform(')->toContain("form.setError('meta',")->toContain("form.clearErrors('meta')");
});

it('submits through the real useForm: parsed JSON is sent, and a bad or missing value sets the field error and sends nothing', function (array $state, array $sent, array $errors) {
    $process = vueRun($this->node, [vueFixture('submit.mjs'), vueFixture('pages/Create.vue'), json_encode(['title' => 't', ...$state])]);
    $result = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(array_map(fn (array $request): array => [$request['url'], $request['data']['meta'], $request['data']['extra_settings'], $request['data']['title']], $result['sent']))->toBe($sent)
        ->and($result['errors'])->toBe($errors);
})->with([
    'valid JSON is sent parsed' => [['meta' => '{"tags": ["a"], "n": 1}', 'extra_settings' => ' [true, null] '], [['/posts', ['tags' => ['a'], 'n' => 1], [true, null], 't']], []],
    'invalid nullable JSON' => [['meta' => '{"tags": [', 'extra_settings' => '[1]'], [], ['meta' => 'The meta field must be valid JSON.']],
    'invalid required JSON' => [['meta' => '', 'extra_settings' => 'not json'], [], ['extra_settings' => 'The extra settings field must be valid JSON.']],
    'empty nullable is null' => [['meta' => "  \n", 'extra_settings' => '{"a": 1}'], [['/posts', null, ['a' => 1], 't']], []],
    'empty required' => [['meta' => '', 'extra_settings' => ' '], [], ['extra_settings' => 'The extra settings field is required.']],
]);

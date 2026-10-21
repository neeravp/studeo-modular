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
    Artisan::call('module:schema', ['name' => 'Article', '--module' => 'Blog', '--fields' => 'slug:string:unique,title:string,status:enum(PostStatus):default(draft),author_id:foreignId:constrained(users):context']);

    fakeInertiaVueInstalled();
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function vueForm(array $arguments): array
{
    $exitCode = Artisan::call('module:vue', ['--module' => 'Blog', ...$arguments]);

    return [$exitCode, Artisan::output()];
}

function vueFiles(object $test): array
{
    return collect(File::allFiles($test->module->path))->map->getPathname()->sort()->values()->all();
}

it('refuses a form option set that is incomplete, shows an example, and writes nothing', function (array $arguments, string $example) {
    $before = vueFiles($this);

    [$exitCode, $output] = vueForm(['name' => 'Posts/Create', ...$arguments]);

    expect($exitCode)->toBe(1)->and($output)->toContain($example)->and(vueFiles($this))->toBe($before);
})->with([
    '--form without --schema' => [['--form' => true, '--action' => 'CreateArticle', '--url' => '/articles'], '--schema=@articles'],
    '--schema without --action' => [['--schema' => '@articles', '--url' => '/articles'], '--action=CreateArticle'],
    '--form with --schema but no --action' => [['--form' => true, '--schema' => '@articles', '--url' => '/articles'], '--action=CreateArticle'],
    '--form without --url' => [['--form' => true, '--schema' => '@articles', '--action' => 'CreateArticle'], '--url=/articles'],
]);

it('refuses a bad --url, and writes nothing', function (string $url) {
    $before = vueFiles($this);

    [$exitCode, $output] = vueForm(['name' => 'Posts/Create', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => $url]);

    expect($exitCode)->toBe(1)->and($output)->toContain('--url=/articles')->and(vueFiles($this))->toBe($before);
})->with(['no slash' => 'articles', 'host' => 'https://evil.test/x', 'protocol relative' => '//evil.test', 'quote' => "/a'b", 'script' => "/a');alert(1);('", 'space' => '/a b', 'dots' => '/a/../b', 'query' => '/a?b=1', 'empty' => '']);

it('refuses --action or --url on a page that is not a form, and writes nothing', function (string $option, string $value) {
    $before = vueFiles($this);

    [$exitCode, $output] = vueForm(['name' => 'Posts/Index', '--page' => true, $option => $value]);

    expect($exitCode)->toBe(1)->and($output)->toContain($option)->and(vueFiles($this))->toBe($before);
})->with([['--action', 'CreateArticle'], ['--url', '/articles']]);

it('reports a schema error before a missing option, and writes nothing', function () {
    $before = vueFiles($this);

    [$exitCode, $output] = vueForm(['name' => 'Posts/Create', '--schema' => 'slug:strng']);

    expect($exitCode)->toBe(1)->and($output)->toContain('Invalid schema:')->and(vueFiles($this))->toBe($before);
});

it('generates the Action, its Input and the page, and reports each', function () {
    [$exitCode, $output] = vueForm(['name' => 'Posts/Create', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => '/articles']);

    expect($exitCode)->toBe(0)
        ->and($this->module->path.'/Actions/CreateArticleAction.php')->toBeFile()
        ->and($this->module->path.'/Data/CreateArticleInput.php')->toBeFile()
        ->and($this->module->path.'/resources/js/Pages/Posts/Create.vue')->toBeFile()
        ->and(File::get($this->module->path.'/Actions/CreateArticleAction.php'))->toContain("DB::table('articles')")
        ->and($output)->toContain('Actions/CreateArticleAction.php')->toContain('Data/CreateArticleInput.php')->toContain('Pages/Posts/Create.vue');
});

it('reuses an existing Action and Input without touching them, and says so', function () {
    Artisan::call('module:action', ['name' => 'CreateArticle', '--module' => 'Blog', '--schema' => '@articles']);
    $hashes = [md5_file($this->module->path.'/Actions/CreateArticleAction.php'), md5_file($this->module->path.'/Data/CreateArticleInput.php')];

    [$exitCode, $output] = vueForm(['name' => 'Posts/Create', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => '/articles']);

    expect($exitCode)->toBe(0)
        ->and([md5_file($this->module->path.'/Actions/CreateArticleAction.php'), md5_file($this->module->path.'/Data/CreateArticleInput.php')])->toBe($hashes)
        ->and($output)->toContain('Reusing')->toContain('CreateArticleAction.php');
});

it('skips a form field the existing Input lacks, with a notice', function () {
    Artisan::call('module:dto', ['name' => 'CreateArticleInput', '--module' => 'Blog', '--schema' => 'slug:string,title:string,author_id:foreignId:context']);

    [$exitCode, $output] = vueForm(['name' => 'Posts/Create', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => '/articles']);
    $page = File::get($this->module->path.'/resources/js/Pages/Posts/Create.vue');

    expect($exitCode)->toBe(0)->and($output)->toContain('Skipped')->toContain('[status]')
        ->and($page)->toContain('v-model="form.slug"')->not->toContain('status');
});

it('takes the context fields from an existing Input', function () {
    Artisan::call('module:dto', ['name' => 'CreateArticleInput', '--module' => 'Blog', '--schema' => 'slug:string,title:string,status:enum(PostStatus):context']);

    vueForm(['name' => 'Posts/Create', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => '/articles']);
    $page = File::get($this->module->path.'/resources/js/Pages/Posts/Create.vue');

    expect($page)->toContain('v-model="form.title"')->not->toContain('status')->not->toContain('author_id');
});

it('never overwrites an existing page, and writes nothing else', function () {
    vueForm(['name' => 'Posts/Create', '--page' => true]);
    $before = vueFiles($this);

    [$exitCode, $output] = vueForm(['name' => 'Posts/Create', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => '/articles']);

    expect($exitCode)->toBe(1)->and($output)->toContain('already exists')->and(vueFiles($this))->toBe($before);
});

it('creates no form, submit or comment text for a page, a component or the module:page alias', function (string $command, array $arguments, string $file) {
    Artisan::call($command, ['name' => 'Plain', '--module' => 'Blog', ...$arguments]);
    $content = File::get($this->module->path.'/resources/js/'.$file);

    expect($content)->not->toContain('useForm')->not->toContain('<form')->not->toContain('submit')->not->toContain('form.')
        ->not->toContain('//')->not->toContain('<!--')->not->toContain('/*')->not->toContain('Link')->not->toContain('Module:')
        ->and(File::glob($this->module->path.'/{Actions,Data}/*.php', GLOB_BRACE))->toBe([]);
})->with([
    'page' => ['module:vue', ['--page' => true], 'Pages/Plain.vue'],
    'alias' => ['module:page', [], 'Pages/Plain.vue'],
    'component' => ['module:vue', [], 'Components/Plain.vue'],
]);

it('writes only inside the module directory', function () {
    vueForm(['name' => 'Posts/Create', '--schema' => '@articles', '--action' => 'CreateArticle', '--url' => '/articles']);

    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

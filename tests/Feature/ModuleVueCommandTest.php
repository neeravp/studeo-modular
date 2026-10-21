<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition(
        name: 'Blog',
        namespace: 'Studeo\\Blog',
        path: $this->rootModulesPath.'/Blog',
    );

    app(CreateModuleAction::class)->execute($this->blogModule);

    // Inertia, Vue and the Vite setup count as installed, so no test runs a shell command
    fakeInertiaVueInstalled();

});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('creates an embedded Vue component by default via module:vue', function () {
    $this->artisan('module:vue', [
        'name' => 'PostCard',
        '--module' => 'Blog',
    ])
        ->expectsOutputToContain('Vue Component [PostCard] created successfully in module [Blog].')
        ->assertSuccessful();

    $path = $this->blogModule->path.'/resources/js/Components/PostCard.vue';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)->toContain('<div class="post-card"></div>')
        ->not->toContain('<!--')->not->toContain('//');
});

it('creates an Inertia full page component with --page flag', function () {
    $this->artisan('module:vue', [
        'name' => 'Posts/Index',
        '--module' => 'Blog',
        '--page' => true,
    ])
        ->expectsOutputToContain('Vue Page [Posts/Index] created successfully in module [Blog].')
        ->expectsOutputToContain("Inertia::render('Blog::Posts/Index')")
        ->assertSuccessful();

    $path = $this->blogModule->path.'/resources/js/Pages/Posts/Index.vue';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)->toContain('<div class="index"></div>')
        ->not->toContain('Link')->not->toContain('Module:');
});

it('creates an Inertia page via module:page alias', function () {
    $this->artisan('module:page', [
        'name' => 'Posts/Show',
        '--module' => 'Blog',
    ])
        ->expectsOutputToContain('Vue Page [Posts/Show] created successfully in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Pages/Posts/Show.vue')->toBeFile();
});

it('refuses --form without a schema, and writes nothing', function () {
    $this->artisan('module:vue', [
        'name' => 'Posts/Create',
        '--module' => 'Blog',
        '--form' => true,
    ])
        ->expectsOutputToContain('A form needs a schema')
        ->assertFailed();

    expect($this->blogModule->path.'/resources/js/Pages/Posts/Create.vue')->not->toBeFile();
});

it('prompts for component name when omitted', function () {
    $this->artisan('module:vue', [
        '--module' => 'Blog',
        '--page' => true,
    ])
        ->expectsQuestion('What is the name of the Vue component?', 'Posts/Edit')
        ->expectsOutputToContain('Vue Page [Posts/Edit] created successfully in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Pages/Posts/Edit.vue')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:vue', [
        'name' => 'Badge',
    ])
        ->expectsChoice('Which module should this belong to?', 'Blog', ['Blog', 'Zeta'])
        ->expectsOutputToContain('Vue Component [Badge] created successfully in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/Badge.vue')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:vue', [
        'name' => 'Ghost',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when component already exists', function () {
    $this->artisan('module:vue', [
        'name' => 'Duplicate',
        '--module' => 'Blog',
    ])->assertSuccessful();

    $this->artisan('module:vue', [
        'name' => 'Duplicate',
        '--module' => 'Blog',
    ])
        ->expectsOutputToContain('Vue Component [Duplicate] already exists in module [Blog].')
        ->assertFailed();
});

it('creates an Inertia form page with schema fields via --schema option', function () {
    $this->writeEnumFixture($this->blogModule, 'PostStatus');
    $this->artisan('module:vue', [
        'name' => 'Posts/Create',
        '--module' => 'Blog',
        '--schema' => 'title:string,content:text,status:enum(PostStatus),is_featured:boolean',
        '--action' => 'CreatePost',
        '--url' => '/posts',
    ])
        ->expectsOutputToContain('Vue Form Page [Posts/Create] created successfully in module [Blog].')
        ->assertSuccessful();

    $path = $this->blogModule->path.'/resources/js/Pages/Posts/Create.vue';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain("title: '',")
        ->toContain("content: '',")
        ->toContain("status: '',")
        ->toContain('is_featured: false,')
        ->toContain('v-model="form.title"')
        ->toContain('v-model="form.content"')
        ->toContain('v-model="form.status"')
        ->toContain('v-model="form.is_featured"');
});

it('has no -s shortcut for --schema', function () {
    expect(fn () => Artisan::call('module:vue', ['name' => 'Posts/Create', '--module' => 'Blog', '-s' => 'title:string', '--action' => 'CreatePost', '--url' => '/posts']))
        ->toThrow(InvalidOptionException::class, 'The "-s" option does not exist.');
});

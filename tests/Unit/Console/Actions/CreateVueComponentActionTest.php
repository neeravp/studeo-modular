<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateVueComponentAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\Enums\VueComponentType;
use Studeo\Modular\Tests\TestCase;

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
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

test('it creates an embedded component in resources/js/Components directory', function () {
    $action = app(CreateVueComponentAction::class);

    $result = $action->execute(
        $this->blogModule,
        'PostCard',
        new VueComponentOptions(type: VueComponentType::Component)
    );
    $path = $result->path;

    expect($path)->toBe($this->blogModule->path.'/resources/js/Components/PostCard.vue')
        ->and($result->createdFiles)->toBe([$path])
        ->and(File::exists($path))->toBeTrue();

    expect(File::get($path))->toContain('<div class="post-card"></div>')->not->toContain('<!--');
});

test('it creates an Inertia page component in resources/js/Pages directory', function () {
    $action = app(CreateVueComponentAction::class);

    $path = $action->execute(
        $this->blogModule,
        'Posts/Index',
        new VueComponentOptions(type: VueComponentType::Page)
    )->path;

    expect($path)->toBe($this->blogModule->path.'/resources/js/Pages/Posts/Index.vue')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('<div class="index"></div>')
        ->not->toContain('Link')->not->toContain('Module:');
});

test('it refuses a form without a schema, an action or a url', function (array $options, string $message) {
    $options = new VueComponentOptions(...['type' => VueComponentType::Form, ...$options]);

    expect(fn () => app(CreateVueComponentAction::class)->execute($this->blogModule, 'Posts/Create', $options))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'no schema' => [[], 'A form needs a schema'],
    'no action' => [['schema' => 'title:string'], 'A form saves through an Action'],
    'no url' => [['schema' => 'title:string', 'action' => 'CreatePost'], 'A form posts to a URL'],
]);

test('it throws an exception when the component already exists', function () {
    $action = app(CreateVueComponentAction::class);

    $action->execute(
        $this->blogModule,
        'PostCard',
        new VueComponentOptions(type: VueComponentType::Component)
    );

    $action->execute(
        $this->blogModule,
        'PostCard',
        new VueComponentOptions(type: VueComponentType::Component)
    );
})->throws(RuntimeException::class, 'Vue Component [PostCard] already exists in module [Blog].');

test('it throws an exception when the module directory does not exist', function () {
    $missingModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    app(CreateVueComponentAction::class)->execute(
        $missingModule,
        'PostCard',
        new VueComponentOptions(type: VueComponentType::Component)
    );
})->throws(RuntimeException::class);

test('it creates a Vue form component with schema fields, useForm state and the Action', function () {
    $this->writeEnumFixture($this->blogModule, 'PostStatus');
    $action = app(CreateVueComponentAction::class);

    $result = $action->execute(
        $this->blogModule,
        'Posts/Create',
        new VueComponentOptions(
            type: VueComponentType::Form,
            schema: 'title:string,content:text,status:enum(PostStatus),is_featured:boolean',
            action: 'CreatePost',
            url: '/posts',
        )
    );

    expect($result->path)->toBeFile()
        ->and($result->createdFiles)->toBe([
            $this->blogModule->path.'/Actions/CreatePostAction.php',
            $this->blogModule->path.'/Data/CreatePostInput.php',
            $result->path,
        ]);

    $content = File::get($result->path);

    expect($content)
        ->toContain("const form = useForm<FormState>({\n  title: '',\n  content: '',\n  status: '',\n  is_featured: false,\n})")
        ->toContain("type FormState = {\n  title: string\n  content: string\n  status: string\n  is_featured: boolean\n}")
        ->toContain("form.post('/posts')")
        ->toContain("<form @submit.prevent=\"submit\" class=\"space-y-6\">\n          <div>\n            <label for=\"title\"")
        ->toContain('<textarea')
        ->toContain('v-model="form.status"')
        ->toContain('type="checkbox"');
});

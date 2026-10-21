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

    $this->shopModule = new ModuleDefinition(
        name: 'Shop',
        namespace: 'Studeo\\Shop',
        path: $this->rootModulesPath.'/Shop',
    );

    app(CreateModuleAction::class)->execute($this->blogModule);
    app(CreateModuleAction::class)->execute($this->shopModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('moves component across modules with --force option', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:move', [
        'name' => 'PostCard',
        'new-name' => 'ItemCard',
        '--module' => 'Blog',
        '--to-module' => 'Shop',
        '--force' => true,
    ])
        ->expectsOutputToContain('Vue Component [PostCard] moved to [ItemCard] in module [Shop].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/PostCard.vue')->not->toBeFile()
        ->and($this->shopModule->path.'/resources/js/Components/ItemCard.vue')->toBeFile();
});

it('renames component within the same module with --force option', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:move', [
        'name' => 'PostCard',
        'new-name' => 'ArticleCard',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Vue Component [PostCard] moved to [ArticleCard] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/PostCard.vue')->not->toBeFile()
        ->and($this->blogModule->path.'/resources/js/Components/ArticleCard.vue')->toBeFile();
});

it('prompts to keep component in same module when --to-module is omitted', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:move', [
        'name' => 'PostCard',
        'new-name' => 'RecentCard',
        '--module' => 'Blog',
    ])
        ->expectsConfirmation('Keep component in module [Blog]?', 'yes')
        ->expectsConfirmation('Are you sure you want to move [PostCard] in [Blog] to [RecentCard]?', 'yes')
        ->expectsOutputToContain('Vue Component [PostCard] moved to [RecentCard] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/RecentCard.vue')->toBeFile();
});

it('prompts for component name when name argument is omitted', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:move', [
        'new-name' => 'ArticleCard',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsQuestion('Which Vue component would you like to move from [Blog]?', 'Components/PostCard')
        ->expectsOutputToContain('Vue Component [PostCard] moved to [ArticleCard] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/ArticleCard.vue')->toBeFile();
});

it('prompts for new name when new-name argument is omitted', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:move', [
        'name' => 'PostCard',
        '--module' => 'Blog',
        '--to-module' => 'Blog',
        '--force' => true,
    ])
        ->expectsQuestion('What should the new component name be?', 'CustomCard')
        ->expectsOutputToContain('Vue Component [PostCard] moved to [CustomCard] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/CustomCard.vue')->toBeFile();
});

it('cancels move when user declines confirmation', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:move', [
        'name' => 'PostCard',
        'new-name' => 'ArticleCard',
        '--module' => 'Blog',
        '--to-module' => 'Blog',
    ])
        ->expectsConfirmation('Are you sure you want to move [PostCard] in [Blog] to [ArticleCard]?', 'no')
        ->expectsOutputToContain('Component [PostCard] was not moved.')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/PostCard.vue')->toBeFile()
        ->and($this->blogModule->path.'/resources/js/Components/ArticleCard.vue')->not->toBeFile();
});

it('supports module:page:move and module:vue:mv aliases', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'Posts/Index', new VueComponentOptions(type: VueComponentType::Page));

    $this->artisan('module:page:move', [
        'name' => 'Posts/Index',
        'new-name' => 'Articles/Index',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Vue Page [Posts/Index] moved to [Articles/Index] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Pages/Articles/Index.vue')->toBeFile();
});

it('fails when component does not exist', function () {
    $this->artisan('module:vue:move', [
        'name' => 'Ghost',
        'new-name' => 'Target',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Vue component [Ghost] does not exist in module [Blog].')
        ->assertFailed();
});

it('fails when target component already exists', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');
    $creator->execute($this->blogModule, 'ArticleCard');

    $this->artisan('module:vue:move', [
        'name' => 'PostCard',
        'new-name' => 'ArticleCard',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Vue Component [ArticleCard] already exists in module [Blog].')
        ->assertFailed();
});

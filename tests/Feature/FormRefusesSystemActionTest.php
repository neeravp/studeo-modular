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
    Artisan::call('module:schema', ['name' => 'Article', '--module' => 'Blog', '--fields' => 'title:string']);
    Artisan::call('module:action', ['name' => 'RecordArticle', '--module' => 'Blog', '--system' => true, '--schema' => '@articles']);
    Artisan::call('module:action', ['name' => 'PublishArticle', '--module' => 'Blog', '--schema' => '@articles']);

    fakeInertiaVueInstalled();
    $this->before = fn () => collect(File::allFiles($this->module->path))->mapWithKeys(fn ($file) => [$file->getPathname() => md5_file($file->getPathname())])->sortKeys()->all();
    $this->snapshot = ($this->before)();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('refuses a Livewire form that saves through a system Action, and writes nothing', function () {
    $exitCode = Artisan::call('module:livewire', ['name' => 'ArticleEditor', '--module' => 'Blog', '--sfc' => true, '--schema' => '@articles', '--action' => 'RecordArticle']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('RecordArticleAction')->toContain('#[SystemAction]')->toContain('not callable from forms')
        ->and(($this->before)())->toBe($this->snapshot);
});

it('refuses a Vue form that saves through a system Action, and writes nothing', function () {
    $exitCode = Artisan::call('module:vue', ['name' => 'Articles/Create', '--module' => 'Blog', '--schema' => '@articles', '--action' => 'RecordArticle', '--url' => '/articles']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('RecordArticleAction')->toContain('#[SystemAction]')->toContain('not callable from forms')
        ->and(($this->before)())->toBe($this->snapshot);
});

it('still saves a form through an existing Action that authorizes', function () {
    expect(Artisan::call('module:livewire', ['name' => 'ArticleEditor', '--module' => 'Blog', '--sfc' => true, '--schema' => '@articles', '--action' => 'PublishArticle']))->toBe(0)
        ->and(Artisan::call('module:vue', ['name' => 'Articles/Create', '--module' => 'Blog', '--schema' => '@articles', '--action' => 'PublishArticle', '--url' => '/articles']))->toBe(0);
});

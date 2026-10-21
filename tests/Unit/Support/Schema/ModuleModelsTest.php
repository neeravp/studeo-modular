<?php

use Studeo\Modular\Support\Schema\ModuleModels;

it('resolves a short or studly name to a model of the module', function (string $given, string $model) {
    $module = moduleWithModels(['Post', 'BlogPost', 'User']);

    expect((new ModuleModels)->resolve($module, $given))->toBe($model);
})->with([
    'studly' => ['Post', 'Post'],
    'short lower case' => ['post', 'Post'],
    'kebab' => ['blog-post', 'BlogPost'],
    'padded' => [' User ', 'User'],
]);

it('refuses a class name, naming the models of the module', function (string $given) {
    $module = moduleWithModels(['Post', 'Category']);

    expect(fn () => (new ModuleModels)->resolve($module, $given))
        ->toThrow(RuntimeException::class, "Model [{$given}] is a class name, and a model must be one of module [Blog]: pass its name, e.g. Category. The module's models: Category, Post.");
})->with([
    'application model' => ['App\\Models\\User'],
    'module model by class' => ['Studeo\\Blog\\Models\\Post'],
    'with a leading slash' => ['\\Post'],
    'with a path' => ['Models/Post'],
]);

it('refuses a name that is not a model of the module, naming the models', function () {
    expect(fn () => (new ModuleModels)->resolve(moduleWithModels(['Post', 'Category']), 'Flight'))
        ->toThrow(RuntimeException::class, "Model [Flight] is not a model of module [Blog]. The module's models: Category, Post.");
});

it('says so when the module has no models', function () {
    expect(fn () => (new ModuleModels)->resolve(moduleWithModels([]), 'Flight'))
        ->toThrow(RuntimeException::class, 'Model [Flight] is not a model of module [Blog]. The module has no models yet.');
});

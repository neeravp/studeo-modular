<?php

use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\Schema\ModuleModels;

it('lists the models of a module in name order', function () {
    $module = moduleWithModels(['Post', 'Category', 'BlogPost']);

    expect((new ModuleModels)->names($module))->toBe(['BlogPost', 'Category', 'Post']);
});

it('lists no models for a module without a Models directory', function () {
    $module = new ModuleDefinition('Ghost', 'Studeo\\Ghost', sys_get_temp_dir().'/modular-no-such-module-'.bin2hex(random_bytes(4)));

    expect((new ModuleModels)->names($module))->toBe([])
        ->and((new ModuleModels)->tables($module))->toBe([]);
});

it('maps each table to the model that declares it, and the first model keeps a shared table', function () {
    $module = moduleWithModels(['Post' => 'posts', 'Article' => 'posts', 'Category' => 'topics']);

    expect((new ModuleModels)->tables($module))->toBe(['posts' => 'Article', 'topics' => 'Category']);
});

it('uses the conventional table for a model that declares none', function () {
    $module = moduleWithModels([]);
    file_put_contents($module->path.'/Models/BlogPost.php', "<?php\n\nnamespace Studeo\\Blog\\Models;\n\nclass BlogPost {}\n");

    expect((new ModuleModels)->tables($module))->toBe(['blog_posts' => 'BlogPost']);
});

it('knows whether a model has a factory file', function () {
    $module = moduleWithModels(['Post', 'Category'], ['Post']);

    expect((new ModuleModels)->hasFactory($module, 'Post'))->toBeTrue()
        ->and((new ModuleModels)->hasFactory($module, 'Category'))->toBeFalse()
        ->and(ModuleModels::factoryFile('Post'))->toBe('database/factories/PostFactory.php');
});

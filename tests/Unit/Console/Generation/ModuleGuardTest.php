<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

test('it does nothing when the module directory exists', function () {
    $guard = new ModuleGuard(new Filesystem);
    $module = new ModuleDefinition('Blog', 'Studeo\\Blog', sys_get_temp_dir());

    $guard->ensureExists($module);

    expect(true)->toBeTrue();
});

test('it throws RuntimeException when the module directory is missing', function () {
    $guard = new ModuleGuard(new Filesystem);
    $module = new ModuleDefinition('Ghost', 'Studeo\\Ghost', '/non/existent/Ghost');

    expect(fn () => $guard->ensureExists($module))
        ->toThrow(RuntimeException::class, 'Module directory does not exist [/non/existent/Ghost].');
});

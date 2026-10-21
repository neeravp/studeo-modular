<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
    $this->pintPath = sys_get_temp_dir().'/modular-pint-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    File::deleteDirectory($this->pintPath);
});

/**
 * G11, batch 2: every generator, with every variant it has, under a prefix that sorts after
 * `Illuminate`, one that sorts before it and no prefix. The table is generatorCases().
 */
it('generates Pint-clean code', function (string $namespace, array $calls, array $paths) {
    $before = $this->rootAppSnapshot();
    blogModule($this, $namespace);
    runGenerators($calls);

    expect(pintChanges($this, casePaths($this, $paths)))->toBe([])
        ->and($this->rootAppSnapshot())->toBe($before);
})->with('namespaces')->with('generators');

it('leaves no placeholder text in any generated PHP file', function () {
    $found = [];

    foreach (generatorCases() as [$calls]) {
        File::deleteDirectory($this->rootModulesPath);
        File::ensureDirectoryExists($this->rootModulesPath);
        blogModule($this, 'Studeo');
        runGenerators($calls);
        $found = [...$found, ...placeholderLines($this, modulePhpFiles($this))];
    }

    expect(array_values(array_unique($found)))->toBe([])
        ->and(File::size($this->rootModulesPath.'/Blog/resources/css/module.css') + File::size($this->rootModulesPath.'/Blog/resources/js/module.js'))->toBe(0);
});

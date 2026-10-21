<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * Anything a generator emits passes every architecture rule on first run.
 * The table is generatorCases(), under the namespaces the Pint conformance test uses.
 */
it('generates code that module:check accepts', function (string $namespace, array $calls) {
    blogModule($this, $namespace);
    runGenerators($calls);

    $exitCode = Artisan::call('module:check', ['--module' => 'Blog', '--format' => 'json']);

    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([])
        ->and($exitCode)->toBe(0);
})->with('namespaces')->with('generators');

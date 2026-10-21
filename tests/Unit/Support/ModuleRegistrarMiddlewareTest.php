<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Exceptions\ModuleRegistrationException;
use Studeo\Modular\Support\ModuleLoadFailures;
use Studeo\Modular\Support\ModuleRegistrar;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\Support\RegistrarModule;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->modulesPath = $this->fixture->rootPath;

    $this->registrarModule = new RegistrarModule($this);
});

afterEach(fn () => $this->fixture->tearDown());

it('aliases middleware under the module key with the relative path', function () {
    $this->registrarModule->registrar($this->registrarModule->build())->middleware();

    expect(app('router')->getMiddleware())->toMatchArray([
        'reg.ensure-active' => 'Studeo\\Reg\\Middleware\\EnsureActive',
        'reg.api.throttle-burst' => 'Studeo\\Reg\\Middleware\\Api\\ThrottleBurst',
    ]);
});

it('gives the same middleware class name in two modules different aliases', function () {
    $this->registrarModule->registrar($this->registrarModule->build('Reg'))->middleware();
    $this->registrarModule->registrar($this->registrarModule->build('Other'))->middleware();

    expect(app('router')->getMiddleware())->toMatchArray([
        'reg.ensure-active' => 'Studeo\\Reg\\Middleware\\EnsureActive',
        'other.ensure-active' => 'Studeo\\Other\\Middleware\\EnsureActive',
    ]);
});

it('reports a boot-phase failure to the shared collector and still runs the other steps', function () {
    app('router')->aliasMiddleware('reg.ensure-active', 'App\\Http\\Middleware\\Taken');

    $this->registrarModule->registrar($this->registrarModule->build())->middleware()->policies();

    $failures = app(ModuleLoadFailures::class)->all();

    expect(app('router')->getMiddleware())->toHaveKey('reg.api.throttle-burst')
        ->and($this->app->make(Gate::class)->policies())->toHaveKey('Studeo\\Reg\\Models\\Flight')
        ->and($failures)->toHaveCount(1)
        ->and($failures[0])->toBeInstanceOf(ModuleLoadException::class)
        ->and($failures[0]->getMessage())->toContain('Module [Reg] failed to load')->toContain('reg.ensure-active');
});

it('reports nothing when every step succeeds', function () {
    $this->registrarModule->registrar($this->registrarModule->build())->routes()->views()->listeners()->policies()->middleware();

    expect(app(ModuleLoadFailures::class)->all())->toBe([]);
});

it('shares one failure collector across the app', function () {
    expect(app(ModuleLoadFailures::class))->toBe(app(ModuleLoadFailures::class));
});

it('registers the same middleware twice without complaint', function () {
    $registrar = $this->registrarModule->registrar($this->registrarModule->build());

    $registrar->middleware()->middleware();

    expect(app('router')->getMiddleware())->toHaveKey('reg.ensure-active');
});

it('does nothing for directories a module does not have', function () {
    $directory = $this->modulesPath.'/Bare';
    $this->writeModuleManifest($directory, 'Bare', 'Studeo\\Bare');

    $registrar = $this->registrarModule->registrar($directory)
        ->config()->routes()->views()->migrations()->translations()
        ->commands()->listeners()->policies()->middleware();

    expect($registrar)->toBeInstanceOf(ModuleRegistrar::class);
});

it('raises a registration exception when the module manifest is unreadable', function () {
    $directory = $this->modulesPath.'/Broken';
    $this->writeRawModuleManifest($directory, '{ broken');

    $this->registrarModule->registrar($directory);
})->throws(ModuleRegistrationException::class, 'Broken');

<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateMiddlewareAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->accountModule = new ModuleDefinition(
        name: 'Account',
        namespace: 'Studeo\\Account',
        path: $this->rootModulesPath.'/Account',
    );

    app(CreateModuleAction::class)->execute($this->accountModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

test('it creates a middleware in the module', function () {
    $action = app(CreateMiddlewareAction::class);

    $path = $action->execute($this->accountModule, 'EnsureAccountIsActive');

    expect($path)->toBe($this->accountModule->path.'/Middleware/EnsureAccountIsActive.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Middleware;')
        ->toContain('use Closure;')
        ->toContain('use Illuminate\Http\Request;')
        ->toContain('use Symfony\Component\HttpFoundation\Response;')
        ->toContain('class EnsureAccountIsActive')
        ->toContain('public function handle(Request $request, Closure $next): Response')
        ->toContain('return $next($request);');
});

test('it preserves explicit Middleware suffix if provided', function () {
    $action = app(CreateMiddlewareAction::class);

    $path = $action->execute($this->accountModule, 'EnsureAccountIsActiveMiddleware');

    expect($path)->toBe($this->accountModule->path.'/Middleware/EnsureAccountIsActiveMiddleware.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('class EnsureAccountIsActiveMiddleware');
});

test('it creates Middleware directory on demand if it does not exist', function () {
    File::deleteDirectory($this->accountModule->path.'/Middleware');

    $action = app(CreateMiddlewareAction::class);
    $path = $action->execute($this->accountModule, 'VerifyApiKey');

    expect(File::isDirectory($this->accountModule->path.'/Middleware'))->toBeTrue()
        ->and(File::exists($path))->toBeTrue();
});

test('it sanitizes whitespace and slashes in middleware name', function () {
    $action = app(CreateMiddlewareAction::class);

    $path = $action->execute($this->accountModule, '  /check_device_header/  ');

    expect($path)->toBe($this->accountModule->path.'/Middleware/CheckDeviceHeader.php')
        ->and(File::exists($path))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateMiddlewareAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'EnsureAccountIsActive'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when middleware already exists in module', function () {
    $action = app(CreateMiddlewareAction::class);

    $action->execute($this->accountModule, 'EnsureAccountIsActive');

    expect(fn () => $action->execute($this->accountModule, 'EnsureAccountIsActive'))
        ->toThrow(RuntimeException::class, 'Middleware [EnsureAccountIsActive] already exists in module [Account].');
});

test('it throws an exception on invalid or empty middleware name', function () {
    $action = app(CreateMiddlewareAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid middleware name [   ///   ].');
});

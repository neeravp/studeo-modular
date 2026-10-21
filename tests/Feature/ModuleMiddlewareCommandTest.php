<?php

use Illuminate\Support\Facades\File;
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

it('creates a middleware with name argument successfully', function () {
    $this->artisan('module:middleware', [
        'name' => 'EnsureAccountIsActive',
        '--module' => 'Account',
    ])
        ->expectsOutput('Middleware [EnsureAccountIsActive] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Middleware/EnsureAccountIsActive.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Middleware;')
        ->toContain('class EnsureAccountIsActive')
        ->toContain('public function handle(Request $request, Closure $next): Response');
});

it('prompts for middleware name when omitted', function () {
    $this->artisan('module:middleware', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the middleware be named?', 'CheckUserStatus')
        ->expectsOutput('Middleware [CheckUserStatus] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Middleware/CheckUserStatus.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:middleware', [
        'name' => 'ValidateClientSignature',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Middleware [ValidateClientSignature] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Middleware/ValidateClientSignature.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:middleware', [
        'name' => 'GhostMiddleware',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when middleware already exists', function () {
    $this->artisan('module:middleware', [
        'name' => 'DuplicateMiddleware',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:middleware', [
        'name' => 'DuplicateMiddleware',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('Middleware [DuplicateMiddleware] already exists in module [Account].')
        ->assertFailed();
});

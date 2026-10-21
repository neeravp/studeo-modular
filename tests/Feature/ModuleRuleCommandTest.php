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

it('creates a validation rule with name argument successfully', function () {
    $this->artisan('module:rule', [
        'name' => 'Uppercase',
        '--module' => 'Account',
    ])
        ->expectsOutput('Validation rule [Uppercase] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Rules/Uppercase.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Rules;')
        ->toContain('class Uppercase implements ValidationRule')
        ->toContain('public function validate(string $attribute, mixed $value, Closure $fail): void')
        ->not->toContain('$implicit');
});

it('creates an implicit validation rule with --implicit flag', function () {
    $this->artisan('module:rule', [
        'name' => 'RequiredIfActive',
        '--module' => 'Account',
        '--implicit' => true,
    ])
        ->expectsOutput('Validation rule [RequiredIfActive] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Rules/RequiredIfActive.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Rules;')
        ->toContain('class RequiredIfActive implements ValidationRule')
        ->toContain('public $implicit = true;')
        ->toContain('public function validate(string $attribute, mixed $value, Closure $fail): void');
});

it('prompts for rule name when omitted', function () {
    $this->artisan('module:rule', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the validation rule be named?', 'ValidVatNumber')
        ->expectsOutput('Validation rule [ValidVatNumber] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Rules/ValidVatNumber.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:rule', [
        'name' => 'ValidVatNumber',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Validation rule [ValidVatNumber] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Rules/ValidVatNumber.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:rule', [
        'name' => 'GhostRule',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when rule already exists', function () {
    $this->artisan('module:rule', [
        'name' => 'DuplicateRule',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:rule', [
        'name' => 'DuplicateRule',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('Rule [DuplicateRule] already exists in module [Account].')
        ->assertFailed();
});

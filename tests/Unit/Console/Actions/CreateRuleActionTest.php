<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateRuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\RuleGenerationOptions;
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

test('it creates a standard validation rule in the module', function () {
    $action = app(CreateRuleAction::class);

    $path = $action->execute($this->accountModule, 'Uppercase');

    expect($path)->toBe($this->accountModule->path.'/Rules/Uppercase.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Rules;')
        ->toContain('use Closure;')
        ->toContain('use Illuminate\Contracts\Validation\ValidationRule;')
        ->toContain('use Illuminate\Translation\PotentiallyTranslatedString;')
        ->toContain('class Uppercase implements ValidationRule')
        ->toContain('public function validate(string $attribute, mixed $value, Closure $fail): void')
        ->not->toContain('$implicit');
});

test('it creates an implicit validation rule when implicit option is true', function () {
    $action = app(CreateRuleAction::class);

    $options = new RuleGenerationOptions(implicit: true);
    $path = $action->execute($this->accountModule, 'RequiredIfActive', $options);

    expect($path)->toBe($this->accountModule->path.'/Rules/RequiredIfActive.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Rules;')
        ->toContain('class RequiredIfActive implements ValidationRule')
        ->toContain('public $implicit = true;')
        ->toContain('public function validate(string $attribute, mixed $value, Closure $fail): void');
});

test('it creates Rules directory on demand if it does not exist', function () {
    File::deleteDirectory($this->accountModule->path.'/Rules');

    $action = app(CreateRuleAction::class);
    $path = $action->execute($this->accountModule, 'ValidPostalCode');

    expect(File::isDirectory($this->accountModule->path.'/Rules'))->toBeTrue()
        ->and(File::exists($path))->toBeTrue();
});

test('it preserves explicit Rule suffix if provided', function () {
    $action = app(CreateRuleAction::class);

    $path = $action->execute($this->accountModule, 'MaxWordsRule');

    expect($path)->toBe($this->accountModule->path.'/Rules/MaxWordsRule.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)->toContain('class MaxWordsRule implements ValidationRule');
});

test('it sanitizes whitespace and slashes in rule name', function () {
    $action = app(CreateRuleAction::class);

    $path = $action->execute($this->accountModule, '  /valid_tax_number/  ');

    expect($path)->toBe($this->accountModule->path.'/Rules/ValidTaxNumber.php')
        ->and(File::exists($path))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateRuleAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'Uppercase'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when rule already exists in module', function () {
    $action = app(CreateRuleAction::class);

    $action->execute($this->accountModule, 'Uppercase');

    expect(fn () => $action->execute($this->accountModule, 'Uppercase'))
        ->toThrow(RuntimeException::class, 'Rule [Uppercase] already exists in module [Account].');
});

test('it throws an exception on invalid or empty rule name', function () {
    $action = app(CreateRuleAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid rule name [   ///   ].');
});

<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreatePolicyAction;
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
    foreach (['Flight', 'User'] as $model) {
        File::put($this->accountModule->path."/Models/{$model}.php", '<?php');
    }
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

test('it creates a plain policy when no model is provided', function () {
    $action = app(CreatePolicyAction::class);

    $path = $action->execute($this->accountModule, 'GeneralPolicy');

    expect($path)->toBeFile();

    expect(File::get($path))->toBe("<?php\n\nnamespace Studeo\\Account\\Policies;\n\nclass GeneralPolicy {}\n");
});

test('it creates a model-bound policy when model name is provided', function () {
    config()->set('auth.providers.users.model', 'App\\Models\\User');

    $action = app(CreatePolicyAction::class);

    $path = $action->execute($this->accountModule, 'FlightPolicy', 'Flight');

    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\\Account\\Policies;')
        ->toContain('use Studeo\\Account\\Models\\Flight;')
        ->toContain('use Illuminate\\Contracts\\Auth\\Authenticatable;')
        ->toContain('class FlightPolicy')
        ->toContain('public function viewAny(Authenticatable $user): bool')
        ->toContain('public function view(Authenticatable $user, Flight $flight): bool')
        ->toContain('public function create(Authenticatable $user): bool')
        ->toContain('public function update(Authenticatable $user, Flight $flight): bool')
        ->toContain('public function delete(Authenticatable $user, Flight $flight): bool')
        ->toContain('public function restore(Authenticatable $user, Flight $flight): bool')
        ->toContain('public function forceDelete(Authenticatable $user, Flight $flight): bool')
        ->not->toContain('App\\')
        ->not->toContain('use Studeo\\Account\\Models\\User;');
});

test('it automatically appends Policy suffix if omitted', function () {
    $action = app(CreatePolicyAction::class);

    $path = $action->execute($this->accountModule, 'Booking');

    expect(basename($path))->toBe('BookingPolicy.php')
        ->and(File::get($path))->toContain('class BookingPolicy');
});

test('it sanitizes whitespace and slashes in policy name', function () {
    $action = app(CreatePolicyAction::class);

    $path = $action->execute($this->accountModule, ' /flight/ ');

    expect(basename($path))->toBe('FlightPolicy.php');
});

test('it refuses fully qualified model names and models the module does not have', function () {
    $action = app(CreatePolicyAction::class);

    expect(fn () => $action->execute($this->accountModule, 'InvoicePolicy', 'Studeo\\Billing\\Models\\Invoice'))
        ->toThrow(RuntimeException::class, "Model [Studeo\\Billing\\Models\\Invoice] is a class name, and a model must be one of module [Account]: pass its name, e.g. Flight. The module's models: Flight, User.")
        ->and(fn () => $action->execute($this->accountModule, 'InvoicePolicy', 'Invoice'))
        ->toThrow(RuntimeException::class, "Model [Invoice] is not a model of module [Account]. The module's models: Flight, User.");
    expect($this->accountModule->path.'/Policies/InvoicePolicy.php')->not->toBeFile();
});

test('it names the model parameter of a User policy so it does not collide with the actor', function () {
    $path = app(CreatePolicyAction::class)->execute($this->accountModule, 'UserPolicy', 'User');

    expect(File::get($path))
        ->toContain('use Studeo\\Account\\Models\\User;')
        ->toContain('public function view(Authenticatable $user, User $model): bool')
        ->toContain('public function viewAny(Authenticatable $user): bool');
});

test('it never reads the configured user model, whatever it is', function () {
    config()->set('auth.providers.users.model', 'App\\Models\\User');

    $plain = File::get(app(CreatePolicyAction::class)->execute($this->accountModule, 'GeneralPolicy'));
    $bound = File::get(app(CreatePolicyAction::class)->execute($this->accountModule, 'FlightPolicy', 'Flight'));

    expect($plain.$bound)->not->toContain('App\\')->not->toContain('Models\\User');
});

test('it throws when module directory does not exist on disk', function () {
    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    $action = app(CreatePolicyAction::class);

    expect(fn () => $action->execute($ghostModule, 'FlightPolicy'))
        ->toThrow(
            RuntimeException::class,
            "Module directory does not exist [{$ghostModule->path}]."
        );
});

test('it throws when policy already exists in module', function () {
    $action = app(CreatePolicyAction::class);

    $action->execute($this->accountModule, 'FlightPolicy');

    expect(fn () => $action->execute($this->accountModule, 'FlightPolicy'))
        ->toThrow(
            RuntimeException::class,
            'Policy [FlightPolicy] already exists in module [Account].'
        );
});

test('it throws when policy name is invalid or empty', function () {
    $action = app(CreatePolicyAction::class);

    expect(fn () => $action->execute($this->accountModule, '///'))
        ->toThrow(
            RuntimeException::class,
            'Invalid policy name [///].'
        );
});

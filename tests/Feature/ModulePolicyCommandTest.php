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

it('creates a plain policy when no model option is passed in non-interactive mode', function () {
    $this->artisan('module:policy', [
        'name' => 'GeneralPolicy',
        '--module' => 'Account',
    ])
        ->expectsOutput('Policy [GeneralPolicy] created successfully in module [Account].')
        ->assertSuccessful();

    $policyPath = $this->accountModule->path.'/Policies/GeneralPolicy.php';
    expect($policyPath)->toBeFile();

    expect(File::get($policyPath))->toBe("<?php\n\nnamespace Studeo\\Account\\Policies;\n\nclass GeneralPolicy {}\n");
});

it('creates a model-bound policy when --model is supplied', function () {
    File::put($this->accountModule->path.'/Models/Flight.php', '<?php');
    $this->artisan('module:policy', [
        'name' => 'FlightPolicy',
        '--module' => 'Account',
        '--model' => 'Flight',
    ])
        ->expectsOutput('Policy [FlightPolicy] created successfully in module [Account].')
        ->assertSuccessful();

    $policyPath = $this->accountModule->path.'/Policies/FlightPolicy.php';
    expect($policyPath)->toBeFile();

    $content = File::get($policyPath);
    expect($content)
        ->toContain('use Studeo\\Account\\Models\\Flight;')
        ->toContain('class FlightPolicy')
        ->toContain('use Illuminate\\Contracts\\Auth\\Authenticatable;')
        ->toContain('public function viewAny(Authenticatable $user): bool')
        ->toContain('public function view(Authenticatable $user, Flight $flight): bool')
        ->toContain('public function delete(Authenticatable $user, Flight $flight): bool')
        ->not->toContain('App\\');
});

it('refuses a --model that is a class name or not in the module, listing the models, and writes nothing', function (string $model, string $message) {
    File::put($this->accountModule->path.'/Models/Flight.php', '<?php');
    $this->artisan('module:policy', ['name' => 'TicketPolicy', '--module' => 'Account', '--model' => $model])
        ->expectsOutput($message)
        ->assertFailed();

    expect($this->accountModule->path.'/Policies/TicketPolicy.php')->not->toBeFile();
})->with([
    'application class' => ['App\\Models\\User', "Model [App\\Models\\User] is a class name, and a model must be one of module [Account]: pass its name, e.g. Flight. The module's models: Flight."],
    'missing' => ['Ticket', "Model [Ticket] is not a model of module [Account]. The module's models: Flight."],
]);

it('resolves a short --model name like module:controller does', function () {
    File::put($this->accountModule->path.'/Models/Flight.php', '<?php');
    $this->artisan('module:policy', ['name' => 'FlightPolicy', '--module' => 'Account', '--model' => 'flight'])->assertSuccessful();

    expect(File::get($this->accountModule->path.'/Policies/FlightPolicy.php'))->toContain('use Studeo\\Account\\Models\\Flight;');
});

it('appends Policy suffix to the generated class name if omitted', function () {
    $this->artisan('module:policy', [
        'name' => 'Booking',
        '--module' => 'Account',
    ])
        ->expectsOutput('Policy [BookingPolicy] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Policies/BookingPolicy.php')->toBeFile();
});

it('prompts for policy name when argument is omitted', function () {
    $this->artisan('module:policy', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What is the name of the policy?', 'InvoicePolicy')
        ->expectsOutput('Policy [InvoicePolicy] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Policies/InvoicePolicy.php')->toBeFile();
});

it('prompts for module name when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:policy', [
        'name' => 'SecurityPolicy',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Policy [SecurityPolicy] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Policies/SecurityPolicy.php')->toBeFile();
});

it('fails when module does not exist on disk', function () {
    $this->artisan('module:policy', [
        'name' => 'GhostPolicy',
        '--module' => 'Ghost',
    ])
        ->expectsOutput("Module directory does not exist [{$this->rootModulesPath}/Ghost].")
        ->assertFailed();
});

it('fails when policy already exists in the module', function () {
    $this->artisan('module:policy', [
        'name' => 'FlightPolicy',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:policy', [
        'name' => 'FlightPolicy',
        '--module' => 'Account',
    ])
        ->expectsOutput('Policy [FlightPolicy] already exists in module [Account].')
        ->assertFailed();
});

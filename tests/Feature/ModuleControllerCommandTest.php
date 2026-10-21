<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\ModuleControllerCommand;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->accountModule = new ModuleDefinition('Account', 'Studeo\\Account', $this->rootModulesPath.'/Account');
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::put($this->accountModule->path.'/Models/Flight.php', '<?php');
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function runController(array $arguments): array
{
    $code = Artisan::call('module:controller', ['--module' => 'Account', ...$arguments]);

    return [$code, Artisan::output()];
}

function controllerMethods(object $test, string $name = 'FlightController'): array
{
    preg_match_all('/public function (\w+)\(/', File::get($test->accountModule->path."/Controllers/{$name}.php"), $matches);

    return $matches[1];
}

it('creates an invokable controller by default', function () {
    $this->artisan('module:controller', ['name' => 'ProvisionServerController', '--module' => 'Account'])
        ->expectsOutput('Controller [ProvisionServerController] created successfully in module [Account].')
        ->assertSuccessful();

    expect(controllerMethods($this, 'ProvisionServerController'))->toBe(['__invoke']);
});

it('appends the Controller suffix when it is omitted', function () {
    $this->artisan('module:controller', ['name' => 'Passenger', '--module' => 'Account'])
        ->expectsOutput('Controller [PassengerController] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Controllers/PassengerController.php')->toBeFile();
});

it('creates each kind of controller from its option', function (array $flags, array $methods) {
    [$code] = runController(['name' => 'FlightController', ...$flags]);

    expect($code)->toBe(0)->and(controllerMethods($this))->toBe($methods);
})->with([
    'resource' => [['--resource' => true], ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']],
    'resource shortcut' => [['-r' => true], ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']],
    'api' => [['--api' => true], ['index', 'store', 'show', 'update', 'destroy']],
    'standard' => [['--standard' => true], []],
    'inertia' => [['--inertia' => true], ['__invoke']],
    'inertia resource' => [['--resource' => true, '--inertia' => true], ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']],
]);

it('binds the model with --model', function (array $flags) {
    [$code] = runController(['name' => 'FlightController', ...$flags]);

    expect($code)->toBe(0)->and(File::get($this->accountModule->path.'/Controllers/FlightController.php'))
        ->toContain('use Studeo\\Account\\Models\\Flight;')->toContain('public function show(Flight $flight)');
})->with([
    'resource' => [['--resource' => true, '--model' => 'Flight']],
    'with the -r shortcut' => [['-r' => true, '--model' => 'flight']],
    'api' => [['--api' => true, '--model' => 'Flight']],
]);

it('refuses an invalid combination with the exact error, and writes nothing', function (array $flags, string $message) {
    [$code, $output] = runController(['name' => 'FlightController', ...$flags]);

    expect($code)->toBe(1)->and($output)->toContain($message)
        ->and($this->accountModule->path.'/Controllers/FlightController.php')->not->toBeFile();
})->with([
    'resource and api' => [['--resource' => true, '--api' => true], 'The options --resource and --api cannot be combined: choose one controller kind.'],
    'api and standard' => [['--api' => true, '--standard' => true], 'The options --api and --standard cannot be combined: choose one controller kind.'],
    'inertia and api' => [['--inertia' => true, '--api' => true], 'The option --inertia cannot be combined with --api'],
    'inertia and standard' => [['--inertia' => true, '--standard' => true], 'The option --inertia cannot be combined with --standard'],
    'model alone' => [['--model' => 'Flight'], 'The option --model needs --resource or --api'],
    'model and standard' => [['--model' => 'Flight', '--standard' => true], 'The option --model cannot be combined with --standard'],
    'model of another class' => [['--resource' => true, '--model' => 'App\\Models\\User'], 'Model [App\\Models\\User] is a class name'],
    'model not in the module' => [['--resource' => true, '--model' => 'Ticket'], "Model [Ticket] is not a model of module [Account]. The module's models: Flight."],
]);

it('has none of the options of the old command', function (string $option) {
    expect(fn () => Artisan::call('module:controller', ['name' => 'FlightController', '--module' => 'Account', $option => true]))
        ->toThrow(InvalidOptionException::class, "The \"{$option}\" option does not exist.");
})->with(['--invokable', '--plain', '--singleton', '--creatable', '-i', '-s']);

it('refuses -m, which is no longer a shortcut for --model', function () {
    expect(fn () => Artisan::call('module:controller', ['name' => 'FlightController', '--module' => 'Account', '--resource' => true, '-m' => 'Flight']))
        ->toThrow(InvalidOptionException::class, 'The "-m" option does not exist.')
        ->and($this->accountModule->path.'/Controllers/FlightController.php')->not->toBeFile();
});

it('keeps -r as the only shortcut of the signature', function () {
    $definition = app(ModuleControllerCommand::class)->getDefinition();
    $shortcuts = collect($definition->getOptions())
        ->mapWithKeys(fn ($option) => [$option->getName() => $option->getShortcut()])
        ->filter()->all();

    expect($shortcuts)->toBe(['resource' => 'r'])->and($definition->getOption('model')->getShortcut())->toBeNull();
});

it('prompts for the controller name when the argument is omitted', function () {
    $this->artisan('module:controller', ['--module' => 'Account'])
        ->expectsQuestion('What is the name of the controller?', 'BookingController')
        ->expectsOutput('Controller [BookingController] created successfully in module [Account].')
        ->assertSuccessful();
});

it('prompts for the module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:controller', ['name' => 'TicketController'])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Controller [TicketController] created successfully in module [Account].')
        ->assertSuccessful();
});

it('fails when the module does not exist on disk or the controller already exists', function () {
    $this->artisan('module:controller', ['name' => 'GhostController', '--module' => 'Ghost'])
        ->expectsOutput("Module directory does not exist [{$this->rootModulesPath}/Ghost].")
        ->assertFailed();

    runController(['name' => 'FlightController']);
    $this->artisan('module:controller', ['name' => 'FlightController', '--module' => 'Account'])
        ->expectsOutput('Controller [FlightController] already exists in module [Account].')
        ->assertFailed();
});

it('prints no route and writes only inside the module', function () {
    $before = $this->rootAppSnapshot();

    [, $output] = runController(['name' => 'FlightController', '--resource' => true]);

    expect($output)->not->toContain('Route::')->and($this->rootAppSnapshot())->toBe($before);
});

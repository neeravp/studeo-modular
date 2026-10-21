<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateControllerAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\ControllerType;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->accountModule = new ModuleDefinition('Account', 'Studeo\\Account', $this->rootModulesPath.'/Account');
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::put($this->accountModule->path.'/Models/Flight.php', '<?php');
    File::put($this->accountModule->path.'/Models/Passenger.php', '<?php');
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function modelController(object $test, ControllerGenerationOptions $options, string $name = 'FlightController'): string
{
    return File::get(app(CreateControllerAction::class)->execute($test->accountModule, $name, $options));
}

it('binds the model of the module in show, edit, update and destroy of a resource controller', function () {
    expect(modelController($this, new ControllerGenerationOptions(ControllerType::Resource, 'Flight')))->toBe(<<<'PHP'
        <?php

        namespace Studeo\Account\Controllers;

        use Illuminate\Http\Request;
        use Studeo\Account\Models\Flight;

        class FlightController
        {
            public function index() {}

            public function create() {}

            public function store(Request $request) {}

            public function show(Flight $flight) {}

            public function edit(Flight $flight) {}

            public function update(Request $request, Flight $flight) {}

            public function destroy(Flight $flight) {}
        }

        PHP);
});

it('binds the model in an API resource controller, which has no edit', function () {
    $content = modelController($this, new ControllerGenerationOptions(ControllerType::Api, 'flight'));

    expect($content)->toContain('use Studeo\\Account\\Models\\Flight;')
        ->toContain('public function show(Flight $flight)')
        ->toContain('public function update(Request $request, Flight $flight)')
        ->toContain('public function destroy(Flight $flight)')
        ->not->toContain('function edit')->not->toContain('function create');
});

it('binds the model in an Inertia resource controller and still renders only the page', function () {
    $content = modelController($this, new ControllerGenerationOptions(ControllerType::Resource, 'Passenger', inertia: true));

    expect($content)->toContain("use Illuminate\\Http\\Request;\nuse Inertia\\Inertia;\nuse Studeo\\Account\\Models\\Passenger;\n")
        ->toContain('public function show(Passenger $passenger)')
        ->toContain("return Inertia::render('Account::Flight/Show');")
        ->not->toContain('redirect')->not->toContain('route(');
});

it('refuses a model that is a class name or is not in the module, and writes nothing', function (string $model, string $message) {
    expect(fn () => modelController($this, new ControllerGenerationOptions(ControllerType::Resource, $model)))->toThrow(RuntimeException::class, $message);
    expect($this->accountModule->path.'/Controllers/FlightController.php')->not->toBeFile();
})->with([
    'application class' => ['App\\Models\\User', "Model [App\\Models\\User] is a class name, and a model must be one of module [Account]: pass its name, e.g. Flight. The module's models: Flight, Passenger."],
    'module class' => ['Studeo\\Account\\Models\\Flight', "Model [Studeo\\Account\\Models\\Flight] is a class name, and a model must be one of module [Account]: pass its name, e.g. Flight. The module's models: Flight, Passenger."],
    'missing' => ['Ticket', "Model [Ticket] is not a model of module [Account]. The module's models: Flight, Passenger."],
]);

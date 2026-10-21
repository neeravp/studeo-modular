<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateControllerAction;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\ControllerType;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootModulesPath = $this->fixture->rootPath;
    $this->accountModule = $this->fixture->definition('Account');
    $this->fixture->modules('Account');
});

afterEach(fn () => $this->fixture->tearDown());

function generatedController(object $test, ?ControllerGenerationOptions $options = null, string $name = 'FlightController'): string
{
    return File::get(app(CreateControllerAction::class)->execute($test->accountModule, $name, $options));
}

it('creates an invokable controller with an empty body by default', function () {
    expect(generatedController($this))->toBe(<<<'PHP'
        <?php

        namespace Studeo\Account\Controllers;

        use Illuminate\Http\Request;

        class FlightController
        {
            public function __invoke(Request $request) {}
        }

        PHP);
});

it('creates a standard controller with no methods', function () {
    expect(generatedController($this, new ControllerGenerationOptions(ControllerType::Standard)))->toBe(<<<'PHP'
        <?php

        namespace Studeo\Account\Controllers;

        class FlightController {}

        PHP);
});

it('creates a resource controller whose methods take the id', function () {
    expect(generatedController($this, new ControllerGenerationOptions(ControllerType::Resource)))->toBe(<<<'PHP'
        <?php

        namespace Studeo\Account\Controllers;

        use Illuminate\Http\Request;

        class FlightController
        {
            public function index() {}

            public function create() {}

            public function store(Request $request) {}

            public function show(string $id) {}

            public function edit(string $id) {}

            public function update(Request $request, string $id) {}

            public function destroy(string $id) {}
        }

        PHP);
});

it('creates an API resource controller without create and edit', function () {
    expect(generatedController($this, new ControllerGenerationOptions(ControllerType::Api)))->toBe(<<<'PHP'
        <?php

        namespace Studeo\Account\Controllers;

        use Illuminate\Http\Request;

        class FlightController
        {
            public function index() {}

            public function store(Request $request) {}

            public function show(string $id) {}

            public function update(Request $request, string $id) {}

            public function destroy(string $id) {}
        }

        PHP);
});

it('appends the Controller suffix when it is missing, and names the page without it', function () {
    $content = generatedController($this, new ControllerGenerationOptions(inertia: true), 'Passenger');

    expect($content)->toContain('class PassengerController')->toContain("Inertia::render('Account::Passenger')");
    expect($this->accountModule->path.'/Controllers/PassengerController.php')->toBeFile();
});

it('writes controllers that extend nothing and carry no comment, placeholder or App import', function (ControllerGenerationOptions $options) {
    $content = generatedController($this, $options);

    expect($content)->not->toContain('extends')->not->toContain('App\\')->not->toContain('//')->not->toContain('/*')
        ->not->toContain('TODO')->not->toContain('{{')->not->toContain('redirect')->not->toContain('route(');
})->with([
    'default' => [new ControllerGenerationOptions],
    'standard' => [new ControllerGenerationOptions(ControllerType::Standard)],
    'resource' => [new ControllerGenerationOptions(ControllerType::Resource)],
    'api' => [new ControllerGenerationOptions(ControllerType::Api)],
    'inertia' => [new ControllerGenerationOptions(inertia: true)],
    'inertia resource' => [new ControllerGenerationOptions(ControllerType::Resource, inertia: true)],
]);

it('throws if the module directory does not exist', function () {
    $missing = new ModuleDefinition('Missing', 'Studeo\\Missing', $this->rootModulesPath.'/Missing');

    expect(fn () => app(CreateControllerAction::class)->execute($missing, 'FlightController'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$missing->path}].");
});

it('throws if the controller already exists, and on an invalid name', function () {
    generatedController($this);

    expect(fn () => generatedController($this))->toThrow(RuntimeException::class, 'Controller [FlightController] already exists in module [Account].')
        ->and(fn () => generatedController($this, null, '///'))->toThrow(RuntimeException::class, 'Invalid controller name [///].');
});

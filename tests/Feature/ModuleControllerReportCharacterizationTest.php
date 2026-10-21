<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateActionControllerAction;
use Studeo\Modular\Console\Actions\CreateControllerAction;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->fixture->modules('Account');
    File::put($this->rootPath.'/Account/Models/Flight.php', '<?php');
    Artisan::call('module:action', ['name' => 'CreateFlight', '--module' => 'Account', '--schema' => 'destination:string,pilot_id:foreignId:constrained(users):context']);
    $this->driver = $this->fixture->driver([CreateControllerAction::class, CreateActionControllerAction::class]);
    $this->resolved = ['resolve:CreateControllerAction', 'resolve:CreateControllerAction', 'resolve:CreateActionControllerAction', 'resolve:ResolveModuleAction'];
    $this->actions = '<modules>/Account/Actions/';
    $this->data = '<modules>/Account/Data/';
    $this->route = fn (string $url, string $controller): string => "Route: Route::post('{$url}', \\Studeo\\Account\\Controllers\\{$controller}::class);\n";
    $this->head = fn (string $name): string => "Controller [{$name}] created successfully in module [Account].\n";
    $this->reusing = "Reusing the existing <modules>/Account/Actions/CreateFlightAction.php\nReusing the existing <modules>/Account/Data/CreateFlightInput.php\n";
    $this->skipped = "Skipped form field [extra]: the existing Input has no property [extra].\nSkipped form field [note]: the existing Input has no property [note].\n";
    $this->schema = 'destination:string,extra:string,note:string,pilot_id:foreignId:constrained(users):context';
    $this->outcome = fn (int $status, string $stdout, string $stderr = '', int $answered = 0, ?array $resolved = null): array => [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'answered' => $answered,
        'resolved' => $resolved ?? $this->resolved,
    ];
    $this->notice = "Notice: actions.CreateTripAction: Action [CreateTripAction] has no Models/Trip.php in module [Account] to authorize 'create' against, so it authorizes the ability [create-trip] against its Input. That fails closed until the ability is defined, or the Action loads the model and authorizes against the instance.\n";
});

afterEach(fn () => $this->fixture->tearDown());

it('prints only the success line for every kind of plain controller', function (array $flags) {
    $run = $this->driver->run('module:controller', ['name' => 'Foo', '--module' => 'Account', ...$flags]);

    expect($run)->toBe(($this->outcome)(0, ($this->head)('FooController')));
})->with([
    'invokable' => [[]],
    'resource' => [['--resource' => true]],
    'api' => [['--api' => true, '--model' => 'Flight']],
    'standard' => [['--standard' => true]],
    'inertia' => [['--inertia' => true]],
    'inertia resource' => [['--inertia' => true, '--resource' => true]],
]);

it('lists the reused Action and Input after the success line, and no route without --url', function () {
    $run = $this->driver->run('module:controller', ['--module' => 'Account', '--action' => 'CreateFlight', '--context' => ['pilot_id=actor']]);

    expect($run)->toBe(($this->outcome)(0, ($this->head)('CreateFlightController').$this->reusing));
});

it('prints the route last when --url is given', function () {
    $run = $this->driver->run('module:controller', ['--module' => 'Account', '--action' => 'CreateFlight', '--context' => ['pilot_id=actor'], '--url' => '/flights']);

    expect($run)->toBe(($this->outcome)(0, ($this->head)('CreateFlightController').$this->reusing.($this->route)('/flights', 'CreateFlightController')));
});

it('prints the notices in order between the reused files and the route', function () {
    $input = ['--module' => 'Account', '--action' => 'CreateFlight', '--schema' => $this->schema, '--context' => ['pilot_id=actor']];

    expect($this->driver->run('module:controller', $input))->toBe(($this->outcome)(0, ($this->head)('CreateFlightController').$this->reusing.$this->skipped))
        ->and($this->driver->run('module:controller', ['name' => 'Booked', '--url' => '/flights', ...$input]))
        ->toBe(($this->outcome)(0, ($this->head)('BookedController').$this->reusing.$this->skipped.($this->route)('/flights', 'BookedController')));
});

it('lists the created files, and sends the schema notices to stderr, not to the report', function () {
    $input = ['--module' => 'Account', '--action' => 'CreateTrip', '--schema' => 'city:string'];
    $created = "Created {$this->actions}CreateTripAction.php\nCreated {$this->data}CreateTripInput.php\n";

    expect($this->driver->run('module:controller', $input))->toBe(($this->outcome)(0, ($this->head)('CreateTripController').$created, $this->notice))
        ->and(File::exists($this->rootPath.'/Account/Controllers/CreateTripController.php'))->toBeTrue();
});

it('lists the created files and the route, with the stderr notice separate', function () {
    $input = ['--module' => 'Account', '--action' => 'CreateTrip', '--schema' => 'city:string', '--url' => '/trips'];
    $created = "Created {$this->actions}CreateTripAction.php\nCreated {$this->data}CreateTripInput.php\n";

    expect($this->driver->run('module:controller', $input))
        ->toBe(($this->outcome)(0, ($this->head)('CreateTripController').$created.($this->route)('/trips', 'CreateTripController'), $this->notice));
});

it('lists a created Action before a reused Input, then the notice, then the route', function () {
    Artisan::call('module:dto', ['name' => 'CreateTripInput', '--module' => 'Account', '--schema' => 'city:string']);
    $input = ['--module' => 'Account', '--action' => 'CreateTrip', '--schema' => 'city:string,extra:string', '--url' => '/trips'];

    expect($this->driver->run('module:controller', $input))->toBe(($this->outcome)(0, ($this->head)('CreateTripController')
        ."Created {$this->actions}CreateTripAction.php\nReusing the existing {$this->data}CreateTripInput.php\n"
        ."Skipped form field [extra]: the existing Input has no property [extra].\n"
        .($this->route)('/trips', 'CreateTripController'), $this->notice));
});

it('takes the name from a prompt, and prints the question before the success line', function () {
    $run = $this->driver->run('module:controller', ['--module' => 'Account'], ['Bar']);

    expect($run)->toBe(($this->outcome)(0, "\n  What is the name of the controller?\n❯ \n".($this->head)('BarController'), '', 4));
});

it('names the missing name before the module is resolved when nothing can be answered', function () {
    $run = $this->driver->run('module:controller', ['--module' => 'Account'], [], false);

    expect($run)->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:controller FlightController --module=Blog.\n", '', 0, ['resolve:CreateControllerAction', 'resolve:CreateControllerAction', 'resolve:CreateActionControllerAction']));
});

it('reports a failed action controller on stdout with exit 1 and prints no report', function () {
    $run = $this->driver->run('module:controller', ['--module' => 'Account', '--action' => 'Ghost']);

    expect($run)->toBe(($this->outcome)(1, "Action [GhostAction] is not an Action of module [Account]. The module's Actions: CreateFlightAction. Pass --schema to generate it.\n"));
});

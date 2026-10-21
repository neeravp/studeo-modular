<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Account', 'Studeo\\Account', $this->rootModulesPath.'/Account');
    app(CreateModuleAction::class)->execute($this->module);

    expect(Artisan::call('module:action', ['name' => 'CreateFlight', '--module' => 'Account', '--schema' => 'destination:string,pilot_id:foreignId:constrained(users):context']))->toBe(0)
        ->and(Artisan::call('module:action', ['name' => 'Bookings/CreateBooking', '--module' => 'Account', '--schema' => 'seat:string']))->toBe(0)
        ->and(Artisan::call('module:action', ['name' => 'RecalculateFares', '--module' => 'Account', '--system' => true]))->toBe(0);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function runActionController(array $arguments): array
{
    $code = Artisan::call('module:controller', ['--module' => 'Account', ...$arguments]);

    return [$code, Artisan::output()];
}

function accountFile(object $test, string $path): string
{
    return File::get($test->module->path.'/'.$path);
}

it('calls the Action with the validated Input, the context from the actor and returns back, named after the Action', function () {
    [$code, $output] = runActionController(['--action' => 'CreateFlight', '--context' => ['pilot_id=actor']]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Controller [CreateFlightController] created successfully in module [Account].')
        ->and(accountFile($this, 'Controllers/CreateFlightController.php'))->toBe(<<<'PHP'
            <?php

            namespace Studeo\Account\Controllers;

            use Illuminate\Http\Request;
            use Studeo\Account\Actions\CreateFlightAction;
            use Studeo\Account\Data\CreateFlightInput;

            class CreateFlightController
            {
                public function __invoke(Request $request, CreateFlightAction $action)
                {
                    $actor = $request->user() ?? abort(401);
                    $input = CreateFlightInput::fromRequest($request, [
                        'pilot_id' => $actor->getAuthIdentifier(),
                    ]);

                    $action($input, $actor);

                    return back();
                }
            }

            PHP);
});

it('builds the Input from the request alone when it has no context field, and an explicit name wins', function () {
    [$code] = runActionController(['name' => 'BookSeat', '--action' => 'Bookings/CreateBookingAction']);

    expect($code)->toBe(0)
        ->and(accountFile($this, 'Controllers/BookSeatController.php'))->toBe(<<<'PHP'
            <?php

            namespace Studeo\Account\Controllers;

            use Illuminate\Http\Request;
            use Studeo\Account\Actions\Bookings\CreateBookingAction;
            use Studeo\Account\Data\Bookings\CreateBookingInput;

            class BookSeatController
            {
                public function __invoke(Request $request, CreateBookingAction $action)
                {
                    $actor = $request->user() ?? abort(401);
                    $input = CreateBookingInput::fromRequest($request);

                    $action($input, $actor);

                    return back();
                }
            }

            PHP);
});

it('lists the Action and Input it reuses and prints the route for --url', function () {
    [$code, $output] = runActionController(['--action' => 'create-flight', '--context' => ['pilot_id=actor'], '--url' => '/flights']);

    expect($code)->toBe(0)
        ->and($output)->toContain('Reusing the existing '.$this->module->path.'/Actions/CreateFlightAction.php')
        ->toContain('Reusing the existing '.$this->module->path.'/Data/CreateFlightInput.php')
        ->toContain("Route: Route::post('/flights', \\Studeo\\Account\\Controllers\\CreateFlightController::class);");
});

it('generates a missing Action and its Input from --schema, as the forms do', function () {
    File::put($this->module->path.'/Models/Trip.php', '<?php');

    [$code, $output] = runActionController(['--action' => 'CreateTrip', '--schema' => 'city:string,owner_id:foreignId:constrained(users):context', '--context' => ['owner_id=actor']]);

    expect($code)->toBe(0)
        ->and($output)->toContain($this->module->path.'/Actions/CreateTripAction.php')->toContain($this->module->path.'/Data/CreateTripInput.php')
        ->and(accountFile($this, 'Actions/CreateTripAction.php'))->toContain("authorize('create', Trip::class)")
        ->and(accountFile($this, 'Data/CreateTripInput.php'))->toContain('#[Context]')
        ->and(accountFile($this, 'Controllers/CreateTripController.php'))->toContain("'owner_id' => \$actor->getAuthIdentifier(),");
});

it('refuses an Action the module does not have, listing its Actions, and writes nothing', function () {
    [$code, $output] = runActionController(['--action' => 'CreateTrip']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Action [CreateTripAction] is not an Action of module [Account]. The module\'s Actions: Bookings/CreateBookingAction, CreateFlightAction, RecalculateFaresAction. Pass --schema to generate it.')
        ->and($this->module->path.'/Controllers/CreateTripController.php')->not->toBeFile();
});

it('refuses a class name for --action', function () {
    [$code, $output] = runActionController(['--action' => 'Studeo\\Account\\Actions\\CreateFlightAction']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Action [Studeo\\Account\\Actions\\CreateFlightAction] is a class name, and an Action must be one of module [Account]: pass its name or its path inside Actions/, e.g. CreatePost or Posts/CreatePost. The module\'s Actions: Bookings/CreateBookingAction, CreateFlightAction, RecalculateFaresAction.');
});

it('refuses a #[SystemAction], which controllers never call', function () {
    [$code, $output] = runActionController(['--action' => 'RecalculateFares']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Action [RecalculateFaresAction] is a #[SystemAction], and system Actions are not callable from forms or controllers.')
        ->and($this->module->path.'/Controllers/RecalculateFaresController.php')->not->toBeFile();
});

it('refuses an Action whose paired Input is missing unless --schema generates it', function () {
    File::delete($this->module->path.'/Data/CreateFlightInput.php');

    [$code, $output] = runActionController(['--action' => 'CreateFlight']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Action [CreateFlightAction] exists but its paired Input [Data/CreateFlightInput.php] does not. Pass --schema to generate it.');
});

it('needs a source for every context field of the Input, as the Livewire form does', function (array $context, string $message) {
    [$code, $output] = runActionController(['--action' => 'CreateFlight', '--context' => $context]);

    expect($code)->toBe(1)->and($output)->toContain($message)
        ->and($this->module->path.'/Controllers/CreateFlightController.php')->not->toBeFile();
})->with([
    'missing' => [[], 'Context field [pilot_id] needs a source. Valid: --context=pilot_id=actor'],
    'unknown field' => [['pilot_id=actor', 'crew_id=actor'], 'Context field [crew_id] is not a #[Context] field of the Input.'],
    'other source' => [['pilot_id=session'], 'Context source [session] for [pilot_id] is not valid; actor is the only source.'],
]);

it('refuses an invalid --url before writing anything', function () {
    [$code, $output] = runActionController(['--action' => 'CreateTrip', '--schema' => 'city:string', '--url' => 'flights']);

    expect($code)->toBe(1)
        ->and($output)->toContain('The URL [flights] is not valid: use a path that starts with /')
        ->and($this->module->path.'/Actions/CreateTripAction.php')->not->toBeFile()
        ->and($this->module->path.'/Controllers/CreateTripController.php')->not->toBeFile();
});

it('writes no Action when the controller already exists', function () {
    File::put($this->module->path.'/Controllers/CreateTripController.php', '<?php');

    [$code] = runActionController(['--action' => 'CreateTrip', '--schema' => 'city:string']);

    expect($code)->toBe(1)
        ->and($this->module->path.'/Actions/CreateTripAction.php')->not->toBeFile()
        ->and($this->module->path.'/Data/CreateTripInput.php')->not->toBeFile();
});

it('refuses --inertia with --action, naming the later decision', function () {
    [$code, $output] = runActionController(['--action' => 'CreateFlight', '--inertia' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Inertia page props built from DTOs are not generated yet');
});

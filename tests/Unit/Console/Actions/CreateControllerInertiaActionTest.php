<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateControllerAction;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
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

function inertiaGeneratedController(object $test, ?ControllerGenerationOptions $options = null, string $name = 'FlightController'): string
{
    return File::get(app(CreateControllerAction::class)->execute($test->accountModule, $name, $options));
}

it('renders the page of the controller from an invokable Inertia controller', function () {
    expect(inertiaGeneratedController($this, new ControllerGenerationOptions(inertia: true)))->toBe(<<<'PHP'
        <?php

        namespace Studeo\Account\Controllers;

        use Illuminate\Http\Request;
        use Inertia\Inertia;

        class FlightController
        {
            public function __invoke(Request $request)
            {
                return Inertia::render('Account::Flight');
            }
        }

        PHP);
});

it('renders Resource/Method pages from index, create, show and edit of an Inertia resource controller', function () {
    expect(inertiaGeneratedController($this, new ControllerGenerationOptions(ControllerType::Resource, inertia: true)))->toBe(<<<'PHP'
        <?php

        namespace Studeo\Account\Controllers;

        use Illuminate\Http\Request;
        use Inertia\Inertia;

        class FlightController
        {
            public function index()
            {
                return Inertia::render('Account::Flight/Index');
            }

            public function create()
            {
                return Inertia::render('Account::Flight/Create');
            }

            public function store(Request $request) {}

            public function show(string $id)
            {
                return Inertia::render('Account::Flight/Show');
            }

            public function edit(string $id)
            {
                return Inertia::render('Account::Flight/Edit');
            }

            public function update(Request $request, string $id) {}

            public function destroy(string $id) {}
        }

        PHP);
});

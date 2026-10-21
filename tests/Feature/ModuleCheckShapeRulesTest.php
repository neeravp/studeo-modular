<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->work = installerRoot([
        'testbench.yaml' => "providers:\n  - Studeo\\Modular\\Providers\\ModularServiceProvider\n  - Studeo\\Modular\\Tests\\CheckRules\\ConfigProvider\n",
        'vendor/autoload.php' => "<?php require '".dirname(__DIR__, 2)."/vendor/autoload.php';",
    ]);
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    runOk($this->work, ['module:make', 'Blog']);
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
    cleanInstallerRoots();
});

function shapeSystemAction(string $invoke = 'public function __invoke(): void {}'): string
{
    return "<?php\n\nnamespace Blog\\Actions;\n\nuse Studeo\\Modular\\Support\\Attributes\\SystemAction;\nuse Illuminate\\Contracts\\Auth\\Authenticatable;\n\n#[SystemAction]\nfinal class SweepAction\n{\n    {$invoke}\n}\n";
}

it('reports one seeded violation per shape rule as path:line [rule] and exits 1', function (array $files, string $expected) {
    seedModule($this, 'Blog', $files);

    $process = checkCommand($this->work, ['module:check', '--module=Blog']);
    $lines = explode("\n", $process->getOutput());

    expect($process->getExitCode())->toBe(1, checkOutput($process))
        ->and(array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, $expected))))->toHaveCount(1)
        ->and(trim($process->getOutput()))->toContain('1 error');
})->with([
    'system-action-usage reference' => [[
        'Actions/SweepAction.php' => shapeSystemAction(),
        'Controllers/SweepController.php' => "<?php\n\nnamespace Blog\\Controllers;\n\nuse Blog\\Actions\\SweepAction;\n\nfinal class SweepController\n{\n    public function __invoke(SweepAction \$action): void {}\n}\n",
    ], 'modules/Blog/Controllers/SweepController.php:5 [system-action-usage]'],
    'action-authorization system actor' => [['Actions/SweepAction.php' => shapeSystemAction('public function __invoke(Authenticatable $actor): void {}')], 'modules/Blog/Actions/SweepAction.php:8 [action-authorization]'],
    'dto-shape Input extends OutputData' => [['Data/SeededInput.php' => "<?php\n\nnamespace Blog\\Data;\n\nuse Studeo\\Modular\\Support\\OutputData;\n\nfinal readonly class SeededInput extends OutputData\n{\n    protected function __construct() {}\n}\n"], 'modules/Blog/Data/SeededInput.php:7 [dto-shape]'],
    'no-dto-in-livewire-state' => [[
        'Data/PostData.php' => "<?php\n\nnamespace Blog\\Data;\n\nuse Studeo\\Modular\\Support\\OutputData;\n\nfinal readonly class PostData extends OutputData {}\n",
        'Livewire/Editor.php' => "<?php\n\nnamespace Blog\\Livewire;\n\nuse Blog\\Data\\PostData;\nuse Livewire\\Component;\n\nfinal class Editor extends Component\n{\n    public ?PostData \$post = null;\n}\n",
    ], 'modules/Blog/Livewire/Editor.php:10 [no-dto-in-livewire-state]'],
    'controller-shape extends' => [['Controllers/SeededController.php' => "<?php\n\nnamespace Blog\\Controllers;\n\nuse Illuminate\\Routing\\Controller;\n\nfinal class SeededController extends Controller {}\n"], 'modules/Blog/Controllers/SeededController.php:7 [controller-shape]'],
    'controller-shape constructor Action' => [[
        'Actions/PingAction.php' => "<?php\n\nnamespace Blog\\Actions;\n\nuse Illuminate\\Contracts\\Auth\\Authenticatable;\nuse Illuminate\\Support\\Facades\\Gate;\n\nfinal class PingAction\n{\n    public function __invoke(Authenticatable \$actor): void\n    {\n        Gate::forUser(\$actor)->authorize('ping');\n    }\n}\n",
        'Controllers/PingController.php' => "<?php\n\nnamespace Blog\\Controllers;\n\nuse Blog\\Actions\\PingAction;\n\nfinal class PingController\n{\n    public function __construct(private PingAction \$ping) {}\n}\n",
    ], 'modules/Blog/Controllers/PingController.php:9 [controller-shape]'],
]);

it('prints the fix hint in each shape rule finding', function () {
    seedModule($this, 'Blog', ['Controllers/SeededController.php' => "<?php\n\nnamespace Blog\\Controllers;\n\nuse Illuminate\\Routing\\Controller;\n\nfinal class SeededController extends Controller {}\n"]);

    $process = checkCommand($this->work, ['module:check', '--module=Blog']);

    expect($process->getOutput())->toContain('a controller extends nothing')->toContain('Remove the base class');
});

it('reports a normal Action without the actor or the Gate call as [action-authorization]', function () {
    seedModule($this, 'Blog', ['Actions/PingAction.php' => "<?php\n\nnamespace Blog\\Actions;\n\nfinal class PingAction\n{\n    public function __invoke(): void {}\n}\n"]);

    $process = checkCommand($this->work, ['module:check', '--module=Blog']);

    expect($process->getExitCode())->toBe(1, checkOutput($process))
        ->and($process->getOutput())->toContain('modules/Blog/Actions/PingAction.php:5 [action-authorization]')->toContain('Gate::forUser')
        ->and(trim($process->getOutput()))->toContain('1 error');
});

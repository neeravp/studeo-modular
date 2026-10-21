<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

uses(TestCase::class);

beforeEach(function () {
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    File::ensureDirectoryExists($this->rootPath);
    app(CreateModuleAction::class)->execute(new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootPath.'/Blog'));

    $this->tree = function (string $directory): array {
        $found = [];

        foreach (array_diff(scandir($directory), ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            $found[$entry] = is_dir($path) ? ($this->tree)($path) : md5_file($path);
        }

        return $found;
    };
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
});

/**
 * Every command whose name becomes a class: the command, the argument that carries the name, and
 * the type its "Invalid ... name" message gives.
 *
 * @return array<string, array{string, array<string, mixed>, string}>
 */
function classGeneratingCommands(): array
{
    return [
        'action' => ['module:action', ['name' => '%s'], 'action'],
        'command' => ['module:command', ['name' => '%s'], 'command'],
        'controller' => ['module:controller', ['name' => '%s'], 'controller'],
        'event' => ['module:event', ['name' => '%s'], 'event'],
        'factory' => ['module:factory', ['name' => '%s'], 'factory'],
        'job' => ['module:job', ['name' => '%s'], 'job'],
        'listener' => ['module:listener', ['name' => '%s'], 'listener'],
        'livewire classic' => ['module:livewire', ['name' => '%s'], 'component'],
        'livewire classic page' => ['module:livewire', ['name' => '%s', '--page' => true], 'component'],
        'mail' => ['module:mail', ['name' => '%s'], 'mail'],
        'middleware' => ['module:middleware', ['name' => '%s'], 'middleware'],
        'model' => ['module:model', ['name' => '%s'], 'model'],
        'notification' => ['module:notification', ['name' => '%s'], 'notification'],
        'policy' => ['module:policy', ['name' => '%s'], 'policy'],
        'resource' => ['module:resource', ['name' => '%s'], 'resource'],
        'rule' => ['module:rule', ['name' => '%s'], 'rule'],
        'seeder' => ['module:seeder', ['name' => '%s'], 'seeder'],
    ];
}

it('refuses a given name that starts with a digit, before anything is written', function (string $command, array $arguments, string $type, string $name) {
    $before = ($this->tree)($this->rootPath.'/Blog');
    $input = ['--module' => 'Blog', ...array_map(fn (mixed $value): mixed => $value === '%s' ? $name : $value, $arguments)];

    $tester = new CommandTester(Artisan::all()[$command]);
    $status = $tester->execute($input, ['interactive' => false, 'capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe("Invalid {$type} name [{$name}].\n")
        ->and(($this->tree)($this->rootPath.'/Blog'))->toBe($before);
})->with(function () {
    foreach (classGeneratingCommands() as $label => [$command, $arguments, $type]) {
        foreach (['0', '2Fa'] as $name) {
            yield "{$label} given {$name}" => [$command, $arguments, $type, $name];
        }
    }
});

it('refuses a pivot entity that starts with a digit, before anything is written', function (array $arguments, string $message) {
    $before = ($this->tree)($this->rootPath.'/Blog');

    $tester = new CommandTester(Artisan::all()['module:pivot']);
    $status = $tester->execute(['--module' => 'Blog', ...$arguments], ['interactive' => false, 'capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe($message."\n")
        ->and(($this->tree)($this->rootPath.'/Blog'))->toBe($before);
})->with([
    'a zero first model' => [['model1' => '0', 'model2' => 'Flight'], 'Invalid pivot name [0].'],
    'a zero second model' => [['model1' => 'Flight', 'model2' => '0'], 'Invalid pivot name [0].'],
    'a digit-leading model' => [['model1' => '2Fa', 'model2' => 'Flight'], 'Invalid pivot name [2Fa].'],
    'a zero segment that is kept' => [['model1' => 'flight_0_passenger'], 'Invalid pivot name [flight_0_passenger].'],
    'a trailing zero segment' => [['model1' => 'flight_0'], 'Invalid pivot name [flight_0].'],
]);

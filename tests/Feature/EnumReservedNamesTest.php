<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * Whether PHP loads a file in a fresh process, and what it says when it does not.
 */
function loadsInFreshProcess(string $file, string $class): bool
{
    $process = new Process([PHP_BINARY, '-r', 'require $argv[1]; echo $argv[2]::from("x")->name;', $file, $class]);
    $process->run();

    return $process->isSuccessful() && $process->getOutput() === 'X';
}

/**
 * Names that are reserved or merely look risky. The test decides for each by loading a hand-written
 * enum in PHP itself, so the list of refused names has to match what this PHP cannot load.
 */
dataset('enum names', array_combine($names = [
    'Class', 'Enum', 'Interface', 'Trait', 'Function', 'List', 'Array', 'Static', 'Match', 'Default',
    'Abstract', 'And', 'Callable', 'Case', 'Catch', 'Clone', 'Const', 'Continue', 'Declare', 'Do', 'Echo', 'Else', 'Elseif', 'Empty',
    'Eval', 'Exit', 'Die', 'Extends', 'Final', 'Finally', 'Fn', 'For', 'Foreach', 'Global', 'Goto', 'If', 'Implements', 'Include',
    'Instanceof', 'Insteadof', 'Isset', 'Namespace', 'New', 'Or', 'Print', 'Private', 'Protected', 'Public', 'Readonly', 'Require',
    'Return', 'Switch', 'Throw', 'Try', 'Unset', 'Use', 'Var', 'While', 'Xor', 'Yield',
    'Self', 'Parent', 'Int', 'Float', 'Bool', 'String', 'True', 'False', 'Null', 'Void', 'Iterable', 'Object', 'Mixed', 'Never',
    'As', 'Break', 'Enddeclare', 'Endfor', 'Endforeach', 'Endif', 'Endswitch', 'Endwhile',
    'Resource', 'Numeric', 'Exception', 'Closure', 'Stringable', 'UnitEnum', 'BackedEnum', 'DateTime', 'Collection',
], array_map(fn (string $name): array => [$name], $names)));

it('refuses exactly the names PHP cannot load as an enum, and loads every other', function (string $name) {
    $handWritten = sys_get_temp_dir()."/enum-name-{$name}-".bin2hex(random_bytes(3)).'.php';
    File::put($handWritten, "<?php\n\nnamespace Probe;\n\nenum {$name}: string\n{\n    case X = 'x';\n}\n");
    $phpLoadsIt = loadsInFreshProcess($handWritten, "Probe\\{$name}");
    File::delete($handWritten);

    $exitCode = Artisan::call('module:enum', ['name' => $name, '--module' => 'Blog', '--cases' => 'x']);
    $output = Artisan::output();
    $file = $this->module->path."/Enums/{$name}.php";

    expect($exitCode)->toBe($phpLoadsIt ? 0 : 1);

    $phpLoadsIt
        ? expect(loadsInFreshProcess($file, "Studeo\\Blog\\Enums\\{$name}"))->toBeTrue()
        : expect($output)->toContain("[{$name}]")->toContain('PHP cannot use')->and($file)->not->toBeFile();
})->with('enum names');

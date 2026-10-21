<?php

use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\Console\Prompts\PromptConsole;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

uses(TestCase::class);

beforeEach(function () {
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    File::ensureDirectoryExists($this->rootPath);
    $this->log = new ArrayObject;
    $this->answer = 'Shop';
    Prompt::setOutput(new BufferedOutput);
    Prompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(function (SelectPrompt $prompt) {
        $this->log[] = 'select: '.$prompt->label.' ['.implode(', ', $prompt->options).']';

        return $this->answer;
    });
    foreach ([DiscoverModulesAction::class, ResolveModuleAction::class] as $class) {
        app()->resolving($class, fn () => $this->log[] = 'resolve: '.class_basename($class));
    }

    $this->console = fn (bool $canPrompt): PromptConsole => new class($this->log, $canPrompt) implements PromptConsole
    {
        public function __construct(private readonly ArrayObject $log, private readonly bool $canPrompt) {}

        public function canPrompt(): bool
        {
            $this->log[] = 'canPrompt';

            return $this->canPrompt;
        }

        public function line(string $string)
        {
            $this->log[] = 'line: '.$string;
        }

        public function warn(string $string)
        {
            $this->log[] = 'warn: '.$string;
        }

        public function announceModule(string $message): void
        {
            $this->log[] = 'announce: '.$message;
        }

        public function infoBadge(string $message): void
        {
            $this->log[] = 'badge: '.$message;
        }
    };
    $this->modules = function (string ...$names) {
        foreach ($names as $name) {
            $this->writeModuleManifest("{$this->rootPath}/{$name}", $name, "Studeo\\{$name}");
        }
    };
    $this->picker = fn (): ModulePicker => new ModulePicker(app());
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
    $prompt = new ReflectionClass(Prompt::class);
    $prompt->getProperty('shouldFallback')->setValue(null, false);
    $prompt->getProperty('fallbacks')->setValue(null, []);
});

it('announces the only module before returning it and never asks whether it can prompt', function () {
    ($this->modules)('Blog');

    $module = ($this->picker)()->resolve(($this->console)(false), null);

    expect($module->name)->toBe('Blog')
        ->and($this->log->getArrayCopy())->toBe(['resolve: ResolveModuleAction', 'resolve: DiscoverModulesAction', 'announce: Using module [Blog].']);
});

it('asks with a select when several modules exist and it can prompt', function () {
    ($this->modules)('Shop', 'Blog');

    $module = ($this->picker)()->resolve(($this->console)(true), null);

    expect($module->name)->toBe('Shop')
        ->and($this->log->getArrayCopy())->toBe([
            'resolve: ResolveModuleAction',
            'resolve: DiscoverModulesAction',
            'canPrompt',
            'select: Which module should this belong to? [Blog, Shop]',
        ]);
});

it('never asks the select, and names --module, when several modules exist and it cannot prompt', function () {
    ($this->modules)('Shop', 'Blog');

    expect(fn () => ($this->picker)()->resolve(($this->console)(false), null))
        ->toThrow(RuntimeException::class, 'There is more than one module (Blog, Shop). Pass --module=<name> to choose one.')
        ->and(collect($this->log)->filter(fn (string $entry): bool => str_starts_with($entry, 'select'))->all())->toBe([]);
});

it('fails with the hint and the skipped directories in name order when there are no modules', function () {
    File::ensureDirectoryExists("{$this->rootPath}/Legacy");
    File::put("{$this->rootPath}/Legacy/composer.json", '{}');
    File::ensureDirectoryExists("{$this->rootPath}/Bare");

    $message = null;
    try {
        ($this->picker)()->resolve(($this->console)(true), null);
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toBe("No modules found. Create one with [php artisan module:make Blog].\n"
        ."Skipped [Bare]: no module.json.\n"
        .'Skipped [Legacy]: legacy composer.json found, no module.json. Run [php artisan module:install Legacy] to see the module.json to create.');
});

it('builds the no-modules message from the skipped list alone', function () {
    $picker = ($this->picker)();

    expect($picker->noModulesMessage([]))->toBe('No modules found. Create one with [php artisan module:make Blog].')
        ->and($picker->noModulesMessage(['Old' => 'no module.json.']))->toBe("No modules found. Create one with [php artisan module:make Blog].\nSkipped [Old]: no module.json.");
});

it('does not resolve DiscoverModulesAction when a module is given', function () {
    ($this->modules)('Blog', 'Shop');

    $module = ($this->picker)()->resolve(($this->console)(true), 'Shop');

    expect($module->name)->toBe('Shop')
        ->and($this->log->getArrayCopy())->toBe(['resolve: ResolveModuleAction']);
});

it('resolves ResolveModuleAction only as a dependency of discovery when picking', function () {
    ($this->modules)('Blog');

    ($this->picker)()->resolve(($this->console)(true), null);

    expect(array_slice($this->log->getArrayCopy(), 0, 2))->toBe(['resolve: ResolveModuleAction', 'resolve: DiscoverModulesAction']);
});

it('refuses a blank given name before it resolves anything', function (string $given) {
    expect(fn () => ($this->picker)()->resolve(($this->console)(true), $given))
        ->toThrow(InvalidArgumentException::class, 'The module name is required.')
        ->and($this->log->getArrayCopy())->toBe([]);
})->with(['empty' => [''], 'spaces' => ['   ']]);

it('lets the resolver error of a given module through unchanged', function () {
    ($this->modules)('Blog');
    $this->writeRawModuleManifest("{$this->rootPath}/Broken", '{not json');

    expect(fn () => ($this->picker)()->resolve(($this->console)(true), 'Broken'))
        ->toThrow(Exception::class, "Invalid module manifest {$this->rootPath}/Broken/module.json: Syntax error");
});

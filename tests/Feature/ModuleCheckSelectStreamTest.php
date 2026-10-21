<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->driver = $this->fixture->driver();
    foreach (['Blog', 'Shop'] as $name) {
        $this->writeModuleManifest("{$this->rootPath}/{$name}", $name, "Studeo\\{$name}");
    }
    $this->label = 'Which module should this belong to?';
    $this->property = function (object $object, string $name): mixed {
        return (fn () => $this->{$name})->call($object);
    };
    $this->promptsOutput = function (): object {
        return (new ReflectionMethod(Prompt::class, 'output'))->invoke(null);
    };
    $this->useRealRenderer = function (array $keys): void {
        app()->resolving(ModulePicker::class, function () use ($keys): void {
            $this->driver->resetPrompts();
            Prompt::fake($keys);
            Prompt::setOutput(($this->property)(Artisan::all()['module:check'], 'output'));
        });
    };
});

afterEach(function () {
    $this->fixture->tearDown();
    $prompt = new ReflectionClass(Prompt::class);
    $prompt->getProperty('terminal')->setValue(null, new Terminal);
});

it('keeps stdout to the findings array when the picked module has findings', function () {
    File::ensureDirectoryExists("{$this->rootPath}/Shop/Actions");
    File::put("{$this->rootPath}/Shop/Actions/Broken.php", '<?php class {');

    $result = $this->driver->run('module:check', ['--format' => 'json'], ['Shop']);
    $findings = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toContain($this->label)
        ->and($result['stdout'])->not->toContain($this->label)
        ->and($findings)->toHaveCount(1)
        ->and($findings[0])->toHaveKeys(['rule', 'file', 'line', 'subject', 'severity', 'message'])
        ->and($findings[0]['rule'])->toBe('syntax');
});

it('draws the real prompt on stderr in json format and on stdout in text format', function (string $format, string $stream) {
    ($this->useRealRenderer)([Key::DOWN, Key::ENTER]);
    $options = $format === 'json' ? ['--format' => 'json'] : [];

    $result = $this->driver->run('module:check', $options);

    expect($result['status'])->toBe(0)
        ->and($result[$stream])->toContain($this->label)
        ->and($result[$stream === 'stdout' ? 'stderr' : 'stdout'])->not->toContain($this->label);
})->with([['json', 'stderr'], ['text', 'stdout']]);

it('points Prompts and the components back at the command output after the picker', function (array $options, array $answers, bool $interactive) {
    $command = Artisan::all()['module:check'];

    $this->driver->run('module:check', ['--format' => 'json', ...$options], $answers, $interactive);
    $output = ($this->property)($command, 'output');
    $components = ($this->property)($command, 'components');

    expect(($this->promptsOutput)())->toBe($output)
        ->and(($this->property)($components, 'output'))->toBe($output);
})->with([
    'after a choice' => [[], ['Shop'], true],
    'after a throw with no terminal' => [[], [], false],
    'after a throw for an unknown module' => [['--module' => 'Ghost'], [], true],
]);

it('restores the real prompt output after a choice', function (string $format) {
    ($this->useRealRenderer)([Key::ENTER]);
    $command = Artisan::all()['module:check'];

    $this->driver->run('module:check', ['--format' => $format]);

    expect(($this->promptsOutput)())->toBe(($this->property)($command, 'output'));
})->with(['json', 'text']);

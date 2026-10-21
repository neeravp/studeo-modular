<?php

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Prompt;
use Studeo\Modular\Console\Actions\CheckInertiaVueAction;
use Studeo\Modular\Console\Actions\CheckLivewireAction;
use Studeo\Modular\Console\Actions\CheckViteConfigurationAction;
use Studeo\Modular\Console\Actions\InstallInertiaVueAction;
use Studeo\Modular\Console\Actions\InstallLivewireAction;
use Studeo\Modular\Console\Actions\ScaffoldInertiaFrontendAction;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\Console\Prompts\PromptConsole;
use Studeo\Modular\Console\Prompts\RootAppChangeConfirmer;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

uses(TestCase::class);

beforeEach(function () {
    $this->log = new ArrayObject;
    $this->answer = true;
    Process::fake();
    Prompt::setOutput(new BufferedOutput);
    Prompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(function (ConfirmPrompt $prompt) {
        $this->log[] = 'confirm: '.$prompt->label;

        return $this->answer;
    });

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

    $this->confirmer = function (bool $livewire = true, ?string $root = null, ?string $server = '3.0.1'): RootAppChangeConfirmer {
        $container = new Container;
        $files = new Filesystem;
        $bindings = [
            CheckLivewireAction::class => fn () => new CheckLivewireAction(fn (): ?string => $livewire ? '4.0.0' : null),
            InstallLivewireAction::class => fn () => new InstallLivewireAction,
            CheckInertiaVueAction::class => fn () => new CheckInertiaVueAction($files, $root, fn (): ?string => $server),
            InstallInertiaVueAction::class => fn () => new InstallInertiaVueAction($root, fn () => null),
            CheckViteConfigurationAction::class => fn () => new CheckViteConfigurationAction($files, $root),
            ScaffoldInertiaFrontendAction::class => fn () => new ScaffoldInertiaFrontendAction($files, new StubRenderer, new ArtifactWriter($files), $root),
        ];

        foreach ($bindings as $class => $make) {
            $container->bind($class, function () use ($class, $make) {
                $this->log[] = 'resolve: '.class_basename($class);

                return $make();
            });
        }

        return new RootAppChangeConfirmer($container);
    };
});

afterEach(function () {
    $prompt = new ReflectionClass(Prompt::class);
    $prompt->getProperty('shouldFallback')->setValue(null, false);
    $prompt->getProperty('fallbacks')->setValue(null, []);
});

it('asks nothing and resolves no installer when Livewire is installed', function () {
    ($this->confirmer)()->ensureLivewireIsReady(($this->console)(true));

    expect($this->log->getArrayCopy())->toBe(['resolve: CheckLivewireAction']);
});

it('never asks the question without a terminal and names the command in the error', function () {
    $run = fn () => ($this->confirmer)(false)->ensureLivewireIsReady(($this->console)(false));

    expect($run)->toThrow(RuntimeException::class, "Livewire is not installed. Nothing was changed. Install it with:\n  composer require livewire/livewire:^4.0")
        ->and($this->log->getArrayCopy())->toBe([
            'resolve: CheckLivewireAction',
            "line: Livewire 4 is required to generate Livewire components. To install it:\n  composer require livewire/livewire:^4.0",
            'canPrompt',
        ]);
});

it('prints the instructions before the question and resolves no installer after a no', function () {
    $this->answer = false;
    $run = fn () => ($this->confirmer)(false)->ensureLivewireIsReady(($this->console)(true));

    expect($run)->toThrow(RuntimeException::class)
        ->and(array_slice($this->log->getArrayCopy(), 1))->toBe([
            "line: Livewire 4 is required to generate Livewire components. To install it:\n  composer require livewire/livewire:^4.0",
            'canPrompt',
            'confirm: Livewire 4 is not installed in this project. Install it with composer require livewire/livewire:^4.0?',
        ]);
});

it('says it is installing, then resolves the installer, after a yes', function () {
    ($this->confirmer)(false)->ensureLivewireIsReady(($this->console)(true));

    expect(array_slice($this->log->getArrayCopy(), -2))->toBe(['badge: Installing Livewire 4 with Composer...', 'resolve: InstallLivewireAction']);
    Process::assertRan(fn ($process) => $process->command === ['composer', 'require', 'livewire/livewire:^4.0']);
});

it('refuses an unsupported Inertia without asking and prints the upgrade commands', function () {
    $root = readyFrontendRoot();
    $run = fn () => ($this->confirmer)(true, $root, '2.0.4')->ensureInertiaVueIsReady(($this->console)(true));

    expect($run)->toThrow(RuntimeException::class, "Inertia inertiajs/inertia-laravel 2.x is installed, and this package supports Inertia 3 only. Nothing was changed. Upgrade it yourself with:\n  composer require inertiajs/inertia-laravel:^3.0")
        ->and($this->log->getArrayCopy())->toBe(['resolve: CheckInertiaVueAction', 'resolve: InstallInertiaVueAction']);
});

it('prints the install commands and never asks without a terminal for Inertia', function () {
    $run = fn () => ($this->confirmer)(true, readyFrontendRoot(['package.json']))->ensureInertiaVueIsReady(($this->console)(false));

    expect($run)->toThrow(RuntimeException::class, 'Inertia 3 and Vue are not installed. Nothing was changed. Run the commands above yourself, then run this command again.')
        ->and(array_slice($this->log->getArrayCopy(), 2))->toBe([
            "line: Inertia 3 and Vue are required to generate Vue components. To install them:\n  npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components",
            'canPrompt',
        ]);
});

it('installs Inertia after the line, the question and the badge, in that order', function () {
    ($this->confirmer)(true, readyFrontendRoot(['package.json']))->ensureInertiaVueIsReady(($this->console)(true));

    expect(array_map(fn (string $entry): string => strtok($entry, ':'), array_slice($this->log->getArrayCopy(), 2)))->toBe(['line', 'canPrompt', 'confirm', 'badge']);
});

it('resolves no scaffolder when nothing is pending', function () {
    ($this->confirmer)(true, readyFrontendRoot())->ensureViteIsConfigured(($this->console)(true));

    expect($this->log->getArrayCopy())->toBe(['resolve: CheckViteConfigurationAction']);
});

it('prints the pending files, then says nothing was written, without asking when it cannot prompt', function () {
    ($this->confirmer)(true, readyFrontendRoot(['vite.config.js']))->ensureViteIsConfigured(($this->console)(false));

    $entries = $this->log->getArrayCopy();

    expect(array_slice($entries, 0, 3))->toBe(['resolve: CheckViteConfigurationAction', 'resolve: ScaffoldInertiaFrontendAction', 'line: The Vite and Inertia root files need these changes:'])
        ->and(array_slice($entries, -2))->toBe(['canPrompt', 'line: Nothing was written to your project. Create or change the files above yourself.'])
        ->and(implode("\n", $entries))->not->toContain('confirm:');
});

it('writes the pending files after a yes and stays silent afterwards', function () {
    $root = readyFrontendRoot(['vite.config.js']);
    ($this->confirmer)(true, $root)->ensureViteIsConfigured(($this->console)(true));

    expect(array_slice($this->log->getArrayCopy(), -2))->toBe(['canPrompt', 'confirm: Write the Vite and Inertia files listed above?'])
        ->and($root.'/vite.config.js')->toBeFile();
});

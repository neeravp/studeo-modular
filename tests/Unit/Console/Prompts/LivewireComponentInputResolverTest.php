<?php

use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\DiscoverLivewireComponentsAction;
use Studeo\Modular\Console\Prompts\LivewireComponentInputResolver;
use Studeo\Modular\Console\Prompts\PromptConsole;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\LivewireComponentType;
use Studeo\Modular\Exceptions\NoTerminalException;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

uses(TestCase::class);

beforeEach(function () {
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    File::ensureDirectoryExists($this->rootPath);
    $this->log = new ArrayObject;
    $this->answer = 'UserBadge';
    Prompt::setOutput(new BufferedOutput);
    Prompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(function (SelectPrompt $prompt) {
        $this->log[] = 'select: '.$prompt->label.' ['.implode(', ', $prompt->options).']';

        return $this->answer;
    });
    $this->console = new class($this->log) implements PromptConsole
    {
        public function __construct(private readonly ArrayObject $log, public bool $promptable = true) {}

        public function canPrompt(): bool
        {
            $this->log[] = 'canPrompt';

            return $this->promptable;
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
    $this->example = 'module:livewire:delete Counter --module=Blog --force';
    $this->blog = new ModuleDefinition('Blog', 'Studeo\\Blog', "{$this->rootPath}/Blog");
    $this->empty = new ModuleDefinition('Empty', 'Studeo\\Empty', "{$this->rootPath}/Empty");
    app(CreateModuleAction::class)->execute($this->blog);
    app(CreateModuleAction::class)->execute($this->empty);
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blog, 'PostList');
    $creator->execute($this->blog, 'UserBadge');
    $creator->execute($this->blog, 'Posts/Create', new LivewireComponentOptions(type: LivewireComponentType::Sfc));
    $this->resolver = fn (): LivewireComponentInputResolver => new LivewireComponentInputResolver(app(DiscoverLivewireComponentsAction::class));
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
    $prompt = new ReflectionClass(Prompt::class);
    $prompt->getProperty('shouldFallback')->setValue(null, false);
    $prompt->getProperty('fallbacks')->setValue(null, []);
});

it('returns the component named on the command line without asking or warning', function () {
    $component = ($this->resolver)()->resolve($this->console, $this->blog, 'PostList', $this->example);

    expect($component->name)->toBe('PostList')
        ->and($this->log->getArrayCopy())->toBe([]);
});

it('finds a named component through its kebab alias', function () {
    expect(($this->resolver)()->resolve($this->console, $this->blog, 'user-badge', $this->example)->name)->toBe('UserBadge');
});

it('refuses an unknown name, naming the component and the module', function () {
    expect(fn () => ($this->resolver)()->resolve($this->console, $this->blog, 'Ghost', $this->example))
        ->toThrow(InvalidArgumentException::class, 'Livewire component [Ghost] does not exist in module [Blog].');
});

it('takes an empty name and 0 as names that were given', function () {
    expect(fn () => ($this->resolver)()->resolve($this->console, $this->blog, '', $this->example))
        ->toThrow(InvalidArgumentException::class, 'Livewire component [] does not exist in module [Blog].')
        ->and(fn () => ($this->resolver)()->resolve($this->console, $this->blog, '0', $this->example))
        ->toThrow(InvalidArgumentException::class, 'Livewire component [0] does not exist in module [Blog].');
});

it('offers every component with its type under the verb and returns the chosen one', function () {
    $component = ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example, 'delete');

    expect($component->name)->toBe('UserBadge')
        ->and($this->log->getArrayCopy())->toBe([
            'canPrompt',
            'select: Which Livewire component would you like to delete from [Blog]? [PostList (classic), Posts/create (sfc), UserBadge (classic)]',
        ]);
});

it('chooses a single-file component by its derived name', function () {
    $this->answer = 'Posts/create';

    expect(($this->resolver)()->resolve($this->console, $this->blog, null, $this->example, 'move')->type)->toBe('sfc');
});

it('says select when no verb is given', function () {
    ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example);

    expect($this->log[1])->toStartWith('select: Which Livewire component would you like to select from [Blog]?');
});

it('warns through the console and gives none when the module has no components', function () {
    $component = ($this->resolver)()->resolve($this->console, $this->empty, null, $this->example, 'delete');

    expect($component)->toBeNull()
        ->and($this->log->getArrayCopy())->toBe(['warn: No Livewire components found in module [Empty].']);
});

it('asks whether it can prompt only before it would choose', function () {
    ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example);
    $asked = $this->log->getArrayCopy();
    $this->log->exchangeArray([]);
    ($this->resolver)()->resolve($this->console, $this->empty, null, $this->example);
    ($this->resolver)()->resolve($this->console, $this->blog, 'PostList', $this->example);

    expect($asked[0])->toBe('canPrompt')
        ->and($asked[1])->toStartWith('select: ')
        ->and($this->log->getArrayCopy())->toBe(['warn: No Livewire components found in module [Empty].']);
});

it('refuses to choose without a terminal and shows the example, with no select', function () {
    $this->console->promptable = false;

    expect(fn () => ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example, 'delete'))
        ->toThrow(NoTerminalException::class, 'The [name] argument must be given when the command is not interactive, e.g. module:livewire:delete Counter --module=Blog --force.')
        ->and($this->log->getArrayCopy())->toBe(['canPrompt']);
});

it('still warns about an empty module, and takes a named component, without a terminal', function () {
    $this->console->promptable = false;

    expect(($this->resolver)()->resolve($this->console, $this->empty, null, $this->example))->toBeNull()
        ->and(($this->resolver)()->resolve($this->console, $this->blog, 'PostList', $this->example)->name)->toBe('PostList')
        ->and($this->log->getArrayCopy())->toBe(['warn: No Livewire components found in module [Empty].']);
});

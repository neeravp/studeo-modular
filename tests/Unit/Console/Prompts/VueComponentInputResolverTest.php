<?php

use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateVueComponentAction;
use Studeo\Modular\Console\Actions\DiscoverVueComponentsAction;
use Studeo\Modular\Console\Prompts\PromptConsole;
use Studeo\Modular\Console\Prompts\VueComponentInputResolver;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\Enums\VueComponentType;
use Studeo\Modular\Exceptions\NoTerminalException;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

uses(TestCase::class);

beforeEach(function () {
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    File::ensureDirectoryExists($this->rootPath);
    $this->log = new ArrayObject;
    $this->answer = 'Components/PostCard';
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
    $this->example = 'module:vue:delete Card --module=Blog --force';
    $this->blog = new ModuleDefinition('Blog', 'Studeo\\Blog', "{$this->rootPath}/Blog");
    $this->empty = new ModuleDefinition('Empty', 'Studeo\\Empty', "{$this->rootPath}/Empty");
    app(CreateModuleAction::class)->execute($this->blog);
    app(CreateModuleAction::class)->execute($this->empty);
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blog, 'PostCard');
    $creator->execute($this->blog, 'Index');
    $creator->execute($this->blog, 'Index', new VueComponentOptions(type: VueComponentType::Page));
    $this->resolver = fn (): VueComponentInputResolver => new VueComponentInputResolver(app(DiscoverVueComponentsAction::class));
    $this->offered = fn (string $verb = 'delete'): string => "select: Which Vue component would you like to {$verb} from [Blog]? ";
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
    $prompt = new ReflectionClass(Prompt::class);
    $prompt->getProperty('shouldFallback')->setValue(null, false);
    $prompt->getProperty('fallbacks')->setValue(null, []);
});

it('returns the named component, the page first when the filter is open', function () {
    $resolve = fn (?bool $isPage) => ($this->resolver)()->resolve($this->console, $this->blog, 'Index', $this->example, 'delete', $isPage);

    expect($resolve(null)->displayType())->toBe('Vue Page')
        ->and($resolve(true)->displayType())->toBe('Vue Page')
        ->and($resolve(false)->displayType())->toBe('Vue Component')
        ->and($this->log->getArrayCopy())->toBe([]);
});

it('names the kind in the not-found message for each filter', function () {
    $message = function (?bool $isPage): string {
        try {
            ($this->resolver)()->resolve($this->console, $this->blog, 'Ghost', $this->example, 'delete', $isPage);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return '';
    };

    expect($message(null))->toBe('Vue component [Ghost] does not exist in module [Blog].')
        ->and($message(true))->toBe('Vue Page [Ghost] does not exist in module [Blog].')
        ->and($message(false))->toBe('Vue Component [Ghost] does not exist in module [Blog].');
});

it('takes an empty name and 0 as names that were given', function () {
    expect(fn () => ($this->resolver)()->resolve($this->console, $this->blog, '', $this->example))
        ->toThrow(InvalidArgumentException::class, 'Vue component [] does not exist in module [Blog].')
        ->and(fn () => ($this->resolver)()->resolve($this->console, $this->blog, '0', $this->example))
        ->toThrow(InvalidArgumentException::class, 'Vue component [0] does not exist in module [Blog].');
});

it('offers pages before components with their kind under the verb and returns the chosen one', function () {
    $component = ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example, 'move');

    expect($component->name)->toBe('PostCard')
        ->and($this->log->getArrayCopy())->toBe([
            'canPrompt',
            ($this->offered)('move').'[Index (Vue Page), Index (Vue Component), PostCard (Vue Component)]',
        ]);
});

it('narrows the offered list to pages, or to components', function () {
    $this->answer = 'Pages/Index';
    ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example, 'delete', true);
    $this->answer = 'Components/Index';
    ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example, 'delete', false);

    expect($this->log->getArrayCopy())->toBe([
        'canPrompt',
        ($this->offered)().'[Index (Vue Page)]',
        'canPrompt',
        ($this->offered)().'[Index (Vue Component), PostCard (Vue Component)]',
    ]);
});

it('returns the component, not the page, when both share a base name and the component is chosen', function () {
    $this->answer = 'Components/Index';

    expect(($this->resolver)()->resolve($this->console, $this->blog, null, $this->example)->displayType())->toBe('Vue Component');
});

it('warns about the kind it found none of, through the console', function () {
    foreach ([null, true, false] as $isPage) {
        expect(($this->resolver)()->resolve($this->console, $this->empty, null, $this->example, 'delete', $isPage))->toBeNull();
    }

    expect($this->log->getArrayCopy())->toBe([
        'warn: No Vue components found in module [Empty].',
        'warn: No Vue Pages found in module [Empty].',
        'warn: No Vue Components found in module [Empty].',
    ]);
});

it('says select when no verb is given', function () {
    ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example);

    expect($this->log[1])->toStartWith(($this->offered)('select'));
});

it('asks whether it can prompt only before it would choose', function () {
    ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example);
    $asked = $this->log->getArrayCopy();
    $this->log->exchangeArray([]);
    ($this->resolver)()->resolve($this->console, $this->empty, null, $this->example);
    ($this->resolver)()->resolve($this->console, $this->blog, 'PostCard', $this->example);

    expect($asked[0])->toBe('canPrompt')
        ->and($asked[1])->toStartWith('select: ')
        ->and($this->log->getArrayCopy())->toBe(['warn: No Vue components found in module [Empty].']);
});

it('refuses to choose without a terminal and shows the example, with no select', function () {
    $this->console->promptable = false;

    expect(fn () => ($this->resolver)()->resolve($this->console, $this->blog, null, $this->example, 'delete'))
        ->toThrow(NoTerminalException::class, 'The [name] argument must be given when the command is not interactive, e.g. module:vue:delete Card --module=Blog --force.')
        ->and($this->log->getArrayCopy())->toBe(['canPrompt']);
});

it('still warns about an empty module, and takes a named component, without a terminal', function () {
    $this->console->promptable = false;

    expect(($this->resolver)()->resolve($this->console, $this->empty, null, $this->example))->toBeNull()
        ->and(($this->resolver)()->resolve($this->console, $this->blog, 'PostCard', $this->example)->name)->toBe('PostCard')
        ->and($this->log->getArrayCopy())->toBe(['warn: No Vue components found in module [Empty].']);
});

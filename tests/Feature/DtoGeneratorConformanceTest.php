<?php

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

    $this->module = new ModuleDefinition(
        name: 'Blog',
        namespace: 'Studeo\\Blog',
        path: $this->rootModulesPath.'/Blog',
    );

    app(CreateModuleAction::class)->execute($this->module);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * Load a generated class in a fresh PHP process (php -l cannot catch fatals
 * that only happen on class load), run the snippet and decode its JSON output.
 *
 * @return array<string, mixed>
 */
function runInSubprocess(string $file, string $class, string $snippet): array
{
    $bootstrap = <<<'PHP'
        require $argv[1];
        require $argv[2];
        $app = new Illuminate\Container\Container;
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $app->instance('validator', new Illuminate\Validation\Factory(
            new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en')
        ));
        $class = $argv[3];
        PHP;

    $process = new Process([
        PHP_BINARY, '-r', $bootstrap."\n".$snippet,
        dirname(__DIR__, 2).'/vendor/autoload.php', $file, $class,
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fill a stub placeholder, failing loudly if the stub no longer contains it
 * (otherwise a spacing change would silently leave an empty class).
 */
function injectIntoGenerated(string $path, string $placeholder, string $replacement): void
{
    $original = File::get($path);
    $changed = str_replace($placeholder, $replacement, $original);

    expect($changed)->not->toBe($original, "Placeholder not found in generated file: {$placeholder}");

    File::put($path, $changed);
}

function generateDto(string $name): string
{
    test()->artisan('module:dto', ['name' => $name, '--module' => 'Blog'])->assertSuccessful();

    return test()->module->path."/Data/{$name}.php";
}

it('generates an OutputData that loads and hydrates in a subprocess', function () {
    $path = generateDto('PostData');
    injectIntoGenerated($path, '__construct() {}', "__construct(\n        public string \$title,\n        public int \$authorId = 0,\n    ) {}");

    $result = runInSubprocess($path, 'Studeo\\Blog\\Data\\PostData', <<<'PHP'
        $dto = $class::from(['title' => 'Hello', 'author_id' => '7']);
        echo json_encode(['parent' => get_parent_class($class), 'array' => $dto->toArray()]);
        PHP);

    expect($result['parent'])->toBe('Studeo\\Modular\\Support\\OutputData')
        ->and($result['array'])->toBe(['title' => 'Hello', 'author_id' => 7]);
});

it('generates an InputData with a protected constructor that loads and hydrates in a subprocess', function () {
    $path = generateDto('PostInput');
    injectIntoGenerated($path, '__construct() {}', "__construct(\n        public string \$title,\n    ) {}");
    injectIntoGenerated($path, 'return [];', "return [\n            'title' => ['required', 'string'],\n        ];");

    $result = runInSubprocess($path, 'Studeo\\Blog\\Data\\PostInput', <<<'PHP'
        $dto = $class::fromValidated(['title' => 'Hello', 'ignored' => 'x']);
        echo json_encode([
            'parent' => get_parent_class($class),
            'protectedConstructor' => (new ReflectionClass($class))->getConstructor()->isProtected(),
            'array' => $dto->toArray(),
        ]);
        PHP);

    expect($result['parent'])->toBe('Studeo\\Modular\\Support\\InputData')
        ->and($result['protectedConstructor'])->toBeTrue()
        ->and($result['array'])->toBe(['title' => 'Hello']);
});

it('generates and loads a DTO from a lower-case, spaced name, writing only inside the module', function () {
    $before = $this->rootAppSnapshot();

    $this->artisan('module:dto', ['name' => 'update post input', '--module' => 'Blog'])->assertSuccessful();
    $path = $this->module->path.'/Data/UpdatePostInput.php';
    injectIntoGenerated($path, '__construct() {}', "__construct(\n        public string \$authorName,\n    ) {}");
    injectIntoGenerated($path, 'return [];', "return [\n            'author_name' => ['required', 'string'],\n        ];");

    $result = runInSubprocess($path, 'Studeo\\Blog\\Data\\UpdatePostInput', <<<'PHP'
        $dto = $class::fromValidated(['author_name' => 'Ada']);
        echo json_encode(['array' => $dto->toArray(), 'rules' => array_keys($class::rules())]);
        PHP);

    expect($result['array'])->toBe(['author_name' => 'Ada'])
        ->and($result['rules'])->toBe(['author_name'])
        ->and($this->rootAppSnapshot())->toBe($before);
});

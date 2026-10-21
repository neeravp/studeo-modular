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
    $this->writeEnumFixture($this->module, 'PostStatus');

    fakeLivewireInstalled();
    fakeInertiaVueInstalled();

    $this->broken = [
        'cli' => 'Bad-Name:string,id:string,title:strng,views:integer:uniq,count:integer:default(abc),status:enum(draft,published),owner:morphTo',
        'yaml' => "fields:\n  Bad-Name: string\n  id: string\n  title: strng\n  views: integer:uniq\n  count: integer:default(abc)\n  status: enum(draft,published)\nrelationships:\n  owner: morphTo\n",
        'json' => json_encode(['fields' => ['Bad-Name' => 'string', 'id' => 'string', 'title' => 'strng', 'views' => 'integer:uniq', 'count' => 'integer:default(abc)', 'status' => 'enum(draft,published)'], 'relationships' => ['owner' => 'morphTo']]),
    ];
    $this->files = [];
    $this->filesBefore = fn () => array_map(fn ($file) => $file->getPathname(), File::allFiles($this->module->path));
    $this->before = ($this->filesBefore)();
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    array_map('unlink', $this->files);
});

function schemaFile(object $test, string $extension, string $content): string
{
    $path = sys_get_temp_dir().'/schema-conformance-'.bin2hex(random_bytes(4)).'.'.$extension;
    file_put_contents($path, $content);
    $test->files[] = $path;

    return $path;
}

function expectEveryIssueAndNothingWritten(object $test, int $exitCode): void
{
    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())
        ->toContain('Invalid schema: 8 problems found.')
        ->toContain('fields.Bad-Name:')->toContain('fields.id:')->toContain('fields.title:')->toContain('fields.views:')
        ->toContain('fields.count:')->toContain('fields.status:')->toContain('relationships.owner:')
        ->toContain('Valid: string, char, text')->toContain('module:enum')
        ->and(($test->filesBefore)())->toBe($test->before)
        ->and($test->rootAppSnapshot())->toBe($test->rootAppBefore);
}

it('rejects the same broken schema with every issue and writes nothing, in each input form', function (string $form) {
    $schema = match ($form) {
        'cli' => $this->broken['cli'],
        'inline yaml' => $this->broken['yaml'],
        'inline json' => $this->broken['json'],
        'yaml file' => schemaFile($this, 'yaml', $this->broken['yaml']),
        'json file' => schemaFile($this, 'json', $this->broken['json']),
    };

    expectEveryIssueAndNothingWritten($this, Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => $schema]));
})->with(['cli', 'inline yaml', 'inline json', 'yaml file', 'json file']);

it('validates the schema of every command that takes one', function (string $command, array $arguments) {
    expectEveryIssueAndNothingWritten($this, Artisan::call($command, [...$arguments, '--module' => 'Blog', '--schema' => $this->broken['cli']]));
})->with([
    'model' => ['module:model', ['name' => 'Post']],
    'dto' => ['module:dto', ['name' => 'PostInput']],
    'action' => ['module:action', ['name' => 'CreatePost']],
    'livewire' => ['module:livewire', ['name' => 'PostForm', '--sfc' => true, '--action' => 'CreatePost']],
    'vue' => ['module:vue', ['name' => 'Posts/Create']],
]);

it('checks the enum against the module: a missing file and a default that is not a case', function () {
    $missing = Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => 'status:enum(Missing)']);
    $output = Artisan::output();

    $wrongDefault = Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => 'status:enum(PostStatus):default(archived)']);

    expect($missing)->not->toBe(0)->and($output)->toContain('Enums/Missing.php')->toContain('module:enum Missing --cases=draft,published')
        ->and($wrongDefault)->not->toBe(0)->and(Artisan::output())->toContain('[archived]')->toContain('Valid: draft, published')
        ->and(($this->filesBefore)())->toBe($this->before);
});

it('accepts a valid default of the module enum', function () {
    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => 'status:enum(PostStatus):default(draft)']))->toBe(0)
        ->and($this->module->path.'/Models/Post.php')->toBeFile();
});

it('generates despite warnings and keeps the exit code at zero', function () {
    $exitCode = Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => 'user:belongsTo(User)']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Notice:')->toContain('user_id')
        ->and($this->module->path.'/Models/Post.php')->toBeFile();
});

it('prints warnings to stderr, not stdout, in a real console run', function () {
    $script = <<<'PHP'
        require $argv[1];
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('modular.root_path', $argv[2]);
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        exit($kernel->handle(
            new Symfony\Component\Console\Input\ArrayInput(['command' => 'module:model', 'name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => 'user:belongsTo(User)']),
            new Symfony\Component\Console\Output\ConsoleOutput,
        ));
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $this->rootModulesPath]);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('Notice:')->toContain('user_id')
        ->and($process->getOutput())->not->toContain('Notice:')->toContain('created successfully')
        ->and($this->module->path.'/Models/Post.php')->toBeFile();
});

<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
    $this->blogPath = $this->rootModulesPath.'/Blog';
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * Runs the real command in a fresh PHP process with a closed stdin, outside the testing
 * environment: the input is parsed from argv, so nothing in the test can answer a prompt.
 *
 * @param  array<int, string>  $arguments
 * @return array{exit: int, output: string}
 */
function modelCommandRun(string $modulesPath, array $arguments): array
{
    $script = <<<'PHP'
        require $argv[1];
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('modular.root_path', $argv[2]);
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $app['env'] = 'local';
        $exit = $app->make(Illuminate\Contracts\Console\Kernel::class)->handle(
            new Symfony\Component\Console\Input\ArgvInput(['artisan', 'module:model', ...json_decode($argv[3], true)]),
            $output = new Symfony\Component\Console\Output\BufferedOutput,
        );
        echo json_encode(['exit' => $exit, 'output' => $output->fetch()]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $modulesPath, json_encode($arguments)]);
    $process->run();

    expect($process->getOutput())->not->toBe('', $process->getErrorOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Every file under a directory, by relative path, with its content hash.
 *
 * @return array<string, string>
 */
function moduleTree(string $path): array
{
    $tree = [];

    foreach (File::allFiles($path, true) as $file) {
        $tree[substr($file->getPathname(), strlen($path))] = md5_file($file->getPathname());
    }

    ksort($tree);

    return $tree;
}

function plantFile(string $path, string $contents = "<?php\n\n// existing\n"): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
}

it('refuses when a target exists, names every conflict, and writes nothing', function (array $planted, array $named) {
    foreach ($planted as $file) {
        plantFile($this->blogPath.'/'.$file);
    }
    $before = moduleTree($this->blogPath);

    $run = modelCommandRun($this->rootModulesPath, ['Post', '--module=Blog']);

    expect($run['exit'])->toBe(1);

    foreach ($named as $fragment) {
        expect($run['output'])->toContain($fragment);
    }

    expect(moduleTree($this->blogPath))->toBe($before)
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
})->with([
    'a factory' => [['database/factories/PostFactory.php'], ['Factory [PostFactory] already exists']],
    'a seeder' => [['database/seeders/PostSeeder.php'], ['Seeder [PostSeeder] already exists']],
    'a same-table migration' => [['database/migrations/2020_01_01_000000_create_posts_table.php'], ['2020_01_01_000000_create_posts_table.php', 'table [posts]']],
    'the model itself' => [['Models/Post.php'], ['Model [Post] already exists']],
    'a factory and a seeder' => [
        ['database/factories/PostFactory.php', 'database/seeders/PostSeeder.php'],
        ['Factory [PostFactory] already exists', 'Seeder [PostSeeder] already exists'],
    ],
    'all four' => [
        ['Models/Post.php', 'database/factories/PostFactory.php', 'database/seeders/PostSeeder.php', 'database/migrations/2020_01_01_000000_create_posts_table.php'],
        ['Model [Post]', 'Factory [PostFactory]', 'Seeder [PostSeeder]', 'create_posts_table.php'],
    ],
]);

it('never checks a file the command was told not to write', function (string $existing, string $option, array $written) {
    plantFile($this->blogPath.'/'.$existing);
    $before = moduleTree($this->blogPath);

    $run = modelCommandRun($this->rootModulesPath, ['Post', '--module=Blog', $option]);

    expect($run['exit'])->toBe(0)->and($run['output'])->toContain('created successfully');

    expect(moduleTree($this->blogPath)['/'.$existing])->toBe($before['/'.$existing]);

    foreach ($written as $file) {
        expect($this->blogPath.'/'.$file)->toBeFile();
    }
})->with([
    'a seeder with --no-seeder' => ['database/seeders/PostSeeder.php', '--no-seeder', ['Models/Post.php', 'database/factories/PostFactory.php']],
    'a factory with --no-factory' => ['database/factories/PostFactory.php', '--no-factory', ['Models/Post.php', 'database/seeders/PostSeeder.php']],
    'a migration with --no-migration' => ['database/migrations/2020_01_01_000000_create_posts_table.php', '--no-migration', ['Models/Post.php', 'database/factories/PostFactory.php']],
]);

it('reads the table from the schema when it looks for a same-table migration', function () {
    plantFile($this->blogPath.'/database/migrations/2020_01_01_000000_create_articles_table.php');

    $schema = $this->blogPath.'/schema.yaml';
    File::put($schema, "table: articles\nfields:\n  title: string\n");
    $before = moduleTree($this->blogPath);

    $run = modelCommandRun($this->rootModulesPath, ['Post', '--module=Blog', '--schema='.$schema]);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('table [articles]')
        ->and(moduleTree($this->blogPath))->toBe($before);
});

it('refuses a nested name because models, factories, seeders and tables are flat', function (string $name) {
    $before = moduleTree($this->blogPath);

    $run = modelCommandRun($this->rootModulesPath, [$name, '--module=Blog']);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('flat')->toContain('models, factories, seeders and tables')
        ->and(moduleTree($this->blogPath))->toBe($before)
        ->and($this->blogPath.'/Models/AdminRole.php')->not->toBeFile()
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
})->with(['slash' => 'Admin/Role', 'backslash' => 'Admin\\Role']);

it('gives a missing name without a terminal an error with a full example, and prompts nothing', function () {
    $before = moduleTree($this->blogPath);

    $run = modelCommandRun($this->rootModulesPath, ['--module=Blog']);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('module:model Post --module=Blog')
        ->and(moduleTree($this->blogPath))->toBe($before);
});

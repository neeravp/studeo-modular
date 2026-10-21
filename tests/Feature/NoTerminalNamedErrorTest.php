<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateVueComponentAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\Helpers\OrderedFilesystem;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process as ChildProcess;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture;
    $this->root = $this->fixture->rootPath;
    $this->composerPath = $this->fixture->composerPath;
    $this->fixture->writeRoot(['Studeo\\Blog\\' => 'modules/Blog/']);
    $this->fixture->modules('Blog', 'Shop');
    $blog = new ModuleDefinition('Blog', 'Studeo\\Blog', "{$this->root}/Blog");
    app(CreateLivewireComponentAction::class)->execute($blog, 'PostList');
    app(CreateVueComponentAction::class)->execute($blog, 'PostCard');
    fakeLivewireInstalled();
    fakeInertiaVueInstalled();
    Process::fake();
    $this->driver = $this->fixture->driver();
    $this->snapshot = fn (): array => [
        File::get($this->composerPath),
        ...array_map(fn ($file): string => $file->getPathname().md5_file($file->getPathname()), File::allFiles($this->root, true)),
    ];
    $this->writes = ['put', 'replace', 'append', 'prepend', 'delete', 'move', 'copy', 'ensureDirectoryExists', 'makeDirectory', 'moveDirectory', 'copyDirectory', 'deleteDirectory', 'deleteDirectories', 'cleanDirectory'];
});

afterEach(function () {
    $this->fixture->tearDown();
    cleanInstallerRoots();
});

it('ends in a named error, writes nothing and asks nothing when an answer is needed and there is no terminal', function (string $command, array $input, string $message) {
    $files = new OrderedFilesystem;
    $files->modulesRoot = $this->root;
    app()->instance(Filesystem::class, $files);
    $before = ($this->snapshot)();

    $run = $this->driver->run($command, $input, ['Blog', 'Blog', 'Blog'], false);
    $written = array_filter($files->calls, fn (string $call): bool => in_array(strtok($call, ' '), $this->writes, true));

    expect($run['status'])->toBe(1)
        ->and($run['stdout'])->toBe($message."\n")
        ->and($run['stderr'])->toBe('')
        ->and($run['answered'])->toBe(0)
        ->and($written)->toBe([])
        ->and(($this->snapshot)())->toBe($before);
})->with([
    'event' => ['module:event', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:event OrderPlaced --module=Blog.'],
    'job' => ['module:job', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:job ProcessPodcast --module=Blog.'],
    'listener' => ['module:listener', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:listener SendOrderNotification --module=Blog.'],
    'mail' => ['module:mail', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:mail OrderShipped --module=Blog.'],
    'notification' => ['module:notification', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:notification InvoicePaid --module=Blog.'],
    'factory' => ['module:factory', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:factory FlightFactory --module=Blog.'],
    'seeder' => ['module:seeder', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:seeder FlightSeeder --module=Blog.'],
    'command' => ['module:command', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:command SendEmails --module=Blog.'],
    'middleware' => ['module:middleware', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:middleware EnsureAccountIsActive --module=Blog.'],
    'resource' => ['module:resource', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:resource FlightResource --module=Blog.'],
    'rule' => ['module:rule', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:rule Uppercase --module=Blog.'],
    'policy' => ['module:policy', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:policy FlightPolicy --module=Blog.'],
    'migration' => ['module:migration', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:migration create_flights_table --module=Blog.'],
    'controller' => ['module:controller', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:controller FlightController --module=Blog.'],
    'livewire' => ['module:livewire', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:livewire PostList --module=Blog.'],
    'vue' => ['module:vue', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:vue PostCard --module=Blog.'],
    'pivot' => ['module:pivot', ['--module' => 'Blog'], 'The [model1] argument must be given when the command is not interactive, e.g. module:pivot Flight Passenger --module=Blog.'],
    'make' => ['module:make', [], 'The [module] argument must be given when the command is not interactive, e.g. module:make Account.'],
    'uninstall' => ['module:uninstall', [], 'The [module] argument must be given when the command is not interactive, e.g. module:uninstall Blog.'],
    'remove' => ['module:remove', ['module' => 'Shop'], 'The --force option must be given when the command is not interactive, e.g. module:remove Shop --force.'],
    'livewire delete, no name' => ['module:livewire:delete', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:livewire:delete Counter --module=Blog --force.'],
    'livewire delete, no --force' => ['module:livewire:delete', ['name' => 'PostList', '--module' => 'Blog'], 'The --force option must be given when the command is not interactive, e.g. module:livewire:delete Counter --module=Blog --force.'],
    'vue delete, no name' => ['module:vue:delete', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:vue:delete Card --module=Blog --force.'],
    'vue delete, no --force' => ['module:vue:delete', ['name' => 'PostCard', '--module' => 'Blog'], 'The --force option must be given when the command is not interactive, e.g. module:vue:delete Card --module=Blog --force.'],
    'livewire move, no name' => ['module:livewire:move', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:livewire:move Counter Renamed --module=Blog --force.'],
    'livewire move, name only' => ['module:livewire:move', ['name' => 'PostList', '--module' => 'Blog'], 'The [new-name] argument and the --force option must be given when the command is not interactive, e.g. module:livewire:move Counter Renamed --module=Blog --force.'],
    'livewire move, no --force' => ['module:livewire:move', ['name' => 'PostList', 'new-name' => 'Posts', '--module' => 'Blog', '--to-module' => 'Shop'], 'The --force option must be given when the command is not interactive, e.g. module:livewire:move Counter Renamed --module=Blog --force.'],
    'livewire move, no new name' => ['module:livewire:move', ['name' => 'PostList', '--module' => 'Blog', '--force' => true], 'The [new-name] argument must be given when the command is not interactive, e.g. module:livewire:move Counter Renamed --module=Blog --force.'],
    'vue move, no name' => ['module:vue:move', ['--module' => 'Blog'], 'The [name] argument must be given when the command is not interactive, e.g. module:vue:move Card Renamed --module=Blog --force.'],
    'vue move, name only' => ['module:vue:move', ['name' => 'PostCard', '--module' => 'Blog'], 'The [new-name] argument and the --force option must be given when the command is not interactive, e.g. module:vue:move Card Renamed --module=Blog --force.'],
    'vue move, no --force' => ['module:vue:move', ['name' => 'PostCard', 'new-name' => 'Cards', '--module' => 'Blog', '--to-module' => 'Shop'], 'The --force option must be given when the command is not interactive, e.g. module:vue:move Card Renamed --module=Blog --force.'],
    'vue move, no new name' => ['module:vue:move', ['name' => 'PostCard', '--module' => 'Blog', '--force' => true], 'The [new-name] argument must be given when the command is not interactive, e.g. module:vue:move Card Renamed --module=Blog --force.'],
]);

it('keeps the named errors the checked-name commands already gave, and prompts nothing', function (string $command, array $input, string $message) {
    $run = $this->driver->run($command, ['--module' => 'Blog', ...$input], ['Post'], false);

    expect($run['status'])->toBe(1)
        ->and($run['stdout'])->toBe($message."\n")
        ->and($run['answered'])->toBe(0);
})->with([
    'model' => ['module:model', [], 'The model name is required when the command is not interactive, e.g. module:model Post --module=Blog.'],
    'action' => ['module:action', [], 'The action name is required when the command is not interactive, e.g. CreatePost or CreatePostAction.'],
    'schema' => ['module:schema', [], 'The model name is required when the command is not interactive, e.g. Post.'],
    'enum name' => ['module:enum', ['--cases' => 'draft'], 'The enum name is required when the command is not interactive, e.g. PostStatus.'],
]);

it('never prompts for a value that was given, 0 included', function (string $command, array $input) {
    $run = $this->driver->run($command, $input, [], false);

    expect($run['answered'])->toBe(0)
        ->and($run['stdout'])->not->toContain('must be given when the command is not interactive');
})->with([
    'a name of 0' => ['module:event', ['name' => '0', '--module' => 'Blog']],
    'a forced remove' => ['module:remove', ['module' => 'Shop', '--force' => true]],
    'a forced delete' => ['module:livewire:delete', ['name' => 'PostList', '--module' => 'Blog', '--force' => true]],
]);

it('is a named error in a fresh process whose stdin is /dev/null', function (array $arguments, string $fragment) {
    $project = sys_get_temp_dir().'/modular-no-terminal-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($project);
    File::put($project.'/testbench.yaml', "providers:\n  - Studeo\\Modular\\Providers\\ModularServiceProvider\n");
    File::ensureDirectoryExists($project.'/vendor');
    File::put($project.'/vendor/autoload.php', "<?php require '".dirname(__DIR__, 2)."/vendor/autoload.php';");
    $process = ChildProcess::fromShellCommandline('"$PHP" "$BIN" '.implode(' ', array_map('escapeshellarg', $arguments)).' < /dev/null', $project, ['APP_ENV' => 'local', 'PHP' => PHP_BINARY, 'BIN' => dirname(__DIR__, 2).'/vendor/bin/testbench']);
    $process->setTimeout(60);
    $process->run();
    $output = $process->getOutput().$process->getErrorOutput();
    File::deleteDirectory($project);

    expect($process->getExitCode())->toBe(1, $output)
        ->and($output)->toContain('must be given when the command is not interactive')->toContain($fragment)
        ->and($output)->not->toContain('TypeError')->not->toContain('Required.');
})->with([
    'remove' => [['module:remove', 'Shop'], '--force'],
    'event' => [['module:event', '--module=Blog'], 'module:event OrderPlaced --module=Blog'],
    'make' => [['module:make'], 'module:make Account'],
]);

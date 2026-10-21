<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

uses(TestCase::class);

beforeEach(function () {
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    File::ensureDirectoryExists($this->rootPath);
    app(CreateModuleAction::class)->execute(new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootPath.'/Blog'));

    Process::fake();
    fakeLivewireInstalled();
    fakeInertiaVueInstalled();

    $this->writes = new class extends Filesystem
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function put($path, $contents, $lock = false)
        {
            $this->calls[] = "put {$path}";

            return parent::put($path, $contents, $lock);
        }

        public function ensureDirectoryExists($path, $mode = 0755, $recursive = true)
        {
            $this->calls[] = "ensureDirectoryExists {$path}";

            parent::ensureDirectoryExists($path, $mode, $recursive);
        }

        public function makeDirectory($path, $mode = 0755, $recursive = false, $force = false)
        {
            $this->calls[] = "makeDirectory {$path}";

            return parent::makeDirectory($path, $mode, $recursive, $force);
        }
    };
    app()->instance(Filesystem::class, $this->writes);

    $this->tree = function (string $directory): array {
        $found = [];

        foreach (array_diff(scandir($directory), ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            $found[$entry] = is_dir($path) ? ($this->tree)($path) : md5_file($path);
        }

        return $found;
    };
    $this->run = function (string $command, array $arguments): array {
        $tester = new CommandTester(Artisan::all()[$command]);
        $status = $tester->execute(['--module' => 'Blog', ...$arguments], ['interactive' => false, 'capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay()];
    };
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
    cleanInstallerRoots();
});

it('refuses a class reference or DTO name whose class base name starts with a digit, before anything is written', function (string $command, array $arguments, string $message) {
    $before = ($this->tree)($this->rootPath.'/Blog');

    [$status, $display] = ($this->run)($command, $arguments);

    expect($status)->toBe(1)
        ->and($display)->toBe($message."\n")
        ->and($this->writes->calls)->toBe([])
        ->and(($this->tree)($this->rootPath.'/Blog'))->toBe($before);
})->with(function () {
    foreach (['0', '2Fa', 'Foo\\2Fa'] as $value) {
        yield "listener --event {$value}" => ['module:listener', ['name' => 'Welcome', '--event' => $value], "Invalid event name [{$value}]."];
        yield "factory --model {$value}" => ['module:factory', ['name' => 'Welcome', '--model' => $value], "Invalid model name [{$value}]."];
        yield "seeder --model {$value}" => ['module:seeder', ['name' => 'Welcome', '--model' => $value], "Invalid model name [{$value}]."];
    }

    foreach (['0Data', 'Posts/0Data', '2faInput', 'Posts/9CreateInput'] as $name) {
        yield "dto {$name}" => ['module:dto', ['name' => $name], "Invalid DTO name [{$name}]."];
    }
});

it('keeps the suffix message for a DTO name that fails the suffix check, digit or not', function (string $name) {
    [$status, $display] = ($this->run)('module:dto', ['name' => $name]);

    expect($status)->toBe(1)
        ->and($display)->toContain("DTO name [{$name}] must end in Input or Data; nothing was created.")
        ->and($this->writes->calls)->toBe([]);
})->with(['0', '2fa', 'Posts/9']);

it('still accepts a class base name that starts with a letter and the names that are not classes', function (string $command, array $arguments, string $pattern) {
    [$status] = ($this->run)($command, $arguments);

    expect($status)->toBe(0)->and(glob($this->rootPath.'/Blog/'.$pattern))->toHaveCount(1);
})->with([
    'a listener event with a digit inside' => ['module:listener', ['name' => 'Welcome', '--event' => 'Order2'], 'Listeners/Welcome.php'],
    'a listener event given as a full class name' => ['module:listener', ['name' => 'Welcome', '--event' => 'Foo\\2Fa\\Order'], 'Listeners/Welcome.php'],
    'a factory model with a digit inside' => ['module:factory', ['name' => 'Welcome', '--model' => 'Post2'], 'database/factories/WelcomeFactory.php'],
    'a seeder model with a digit inside' => ['module:seeder', ['name' => 'Welcome', '--model' => 'Post2'], 'database/seeders/WelcomeSeeder.php'],
    'a DTO with a digit inside' => ['module:dto', ['name' => 'Posts/Create2Input'], 'Data/Posts/Create2Input.php'],
    'a command --command of 0' => ['module:command', ['name' => 'Welcome', '--command' => '0'], 'Commands/Welcome.php'],
    'a mail view named 404' => ['module:mail', ['name' => 'Welcome', '--view' => '404'], 'resources/views/404.blade.php'],
    'a mail markdown view named 0' => ['module:mail', ['name' => 'Welcome', '--markdown' => '0'], 'resources/views/0.blade.php'],
    'a notification markdown view named 0' => ['module:notification', ['name' => 'Welcome', '--markdown' => '0'], 'resources/views/0.blade.php'],
    'a migration named 2fa_codes' => ['module:migration', ['name' => '2fa_codes'], 'database/migrations/*_2fa_codes.php'],
    'a single-file Livewire component named 404' => ['module:livewire', ['name' => '404', '--sfc' => true], 'Livewire/⚡404.blade.php'],
    'a Vue component named 0' => ['module:vue', ['name' => '0'], 'resources/js/Components/0.vue'],
]);

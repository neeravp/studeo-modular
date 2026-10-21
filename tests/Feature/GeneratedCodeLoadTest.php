<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
    $this->brokenPath = sys_get_temp_dir().'/modular-load-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    File::deleteDirectory($this->brokenPath);
});

/**
 * `php -l` is not enough. Every PHP class a generator wrote is autoloaded in a
 * fresh process through the module's own manifest namespace; migrations and Livewire multi-file
 * components, which are anonymous classes, are required. The table is generatorCases().
 */
it('loads every generated class in a fresh process', function (string $namespace, array $calls) {
    blogModule($this, $namespace);
    runGenerators($calls);

    $process = loadModuleInFreshProcess($this->rootModulesPath.'/Blog');

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0, $process->getOutput())
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['errors'])->toBe([]);
})->with('namespaces')->with('generators');

it('loads the classes and requires the anonymous files of a module', function () {
    blogModule($this, 'Studeo');
    runGenerators([['module:model', ['name' => 'Post']], ['module:livewire', ['name' => 'Plain', '--mfc' => true]]]);

    $loaded = json_decode(loadModuleInFreshProcess($this->rootModulesPath.'/Blog')->getOutput(), true, flags: JSON_THROW_ON_ERROR)['loaded'];

    expect($loaded)->toContain('Studeo\Blog\Models\Post', 'Studeo\Blog\Database\Factories\PostFactory', 'Studeo\Blog\Database\Seeders\PostSeeder')
        ->and(preg_grep('~database/migrations/.*_create_posts_table\.php~', $loaded))->not->toBeEmpty()
        ->and(preg_grep('~Livewire/.*\.php~', $loaded))->not->toBeEmpty();
});

it('fails a class that lints but cannot be loaded', function () {
    $module = brokenModule($this, ['Models/Broken.php' => "<?php\n\nnamespace Studeo\\Blog\\Models;\n\nclass Base {}\n\nfinal readonly class Broken extends Base {}\n"]);

    $process = loadModuleInFreshProcess($module);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('Broken');
});

it('fails a file that does not declare the class its path promises', function () {
    $module = brokenModule($this, ['Models/Wrong.php' => "<?php\n\nnamespace Studeo\\Blog\\Models;\n\nfinal class Other {}\n"]);

    $process = loadModuleInFreshProcess($module);

    expect($process->getExitCode())->toBe(1)
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['errors'])->toBe(['Models/Wrong.php: Studeo\Blog\Models\Other is not loadable']);
});

it('fails a migration that throws when it is required', function () {
    $module = brokenModule($this, ['database/migrations/2024_01_01_000000_bad.php' => "<?php\n\nreturn new class extends Missing {};\n"]);

    $process = loadModuleInFreshProcess($module);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('Missing');
});

it('fails a migration file that declares no class', function () {
    $module = brokenModule($this, ['database/migrations/2024_01_01_000000_empty.php' => "<?php\n\nreturn [];\n"]);

    $process = loadModuleInFreshProcess($module);

    expect($process->getExitCode())->toBe(1)
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['errors'])->toBe(['database/migrations/2024_01_01_000000_empty.php: declares no class']);
});

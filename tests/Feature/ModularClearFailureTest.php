<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Support\ModuleCache;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

uses(TestCase::class);

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/modular-clear-failure-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->scratch);
    File::put($this->scratch.'/modular.php', '<?php return [];');

    $this->keepFile = function (): void {
        $this->app->instance(ModuleCache::class, new ModuleCache($this->scratch.'/modular.php', new class extends Filesystem
        {
            public function delete($paths): bool
            {
                return false;
            }
        }));
    };
    $this->run = function (array $input): array {
        $output = new BufferedOutput;
        $status = Artisan::call('modular:clear', $input, $output);

        return [$status, $output->fetch()];
    };
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

it('reports the cache file it could not delete, exits 1 and does not claim the cache is cleared', function () {
    ($this->keepFile)();

    [$status, $display] = ($this->run)([]);

    expect($status)->toBe(1)
        ->and($display)->toContain('Unable to delete the module cache')->toContain($this->scratch.'/modular.php')
        ->not->toContain('Module cache cleared.')->not->toContain('#0 ')
        ->and(file_exists($this->scratch.'/modular.php'))->toBeTrue();
});

it('prints the stack trace after the message under -v, with the same exit code, and nothing under --quiet', function () {
    ($this->keepFile)();

    [$status, $display] = ($this->run)(['-v' => true]);
    [$quietStatus, $quiet] = ($this->run)(['--quiet' => true]);

    expect($status)->toBe(1)->and($quietStatus)->toBe(1)
        ->and(strpos($display, '#0 '))->toBeGreaterThan(strpos($display, 'Unable to delete the module cache'))
        ->and($quiet)->toBe('');
});

it('throws from ModuleCache::clear() when the file is still there after the delete', function () {
    ($this->keepFile)();

    expect(fn () => app(ModuleCache::class)->clear())
        ->toThrow(RuntimeException::class, "Unable to delete the module cache [{$this->scratch}/modular.php].");
});

it('prints the success line only when a file was removed, and a missing file stays a silent success', function () {
    $this->app->instance(ModuleCache::class, new ModuleCache($this->scratch.'/modular.php', new Filesystem));

    [$status, $display] = ($this->run)([]);
    [$missingStatus, $missingDisplay] = ($this->run)([]);

    expect($status)->toBe(0)->and($display)->toContain('Module cache cleared.')
        ->and($missingStatus)->toBe(0)->and($missingDisplay)->toBe('')
        ->and(file_exists($this->scratch.'/modular.php'))->toBeFalse();
});

<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\DirectoryPruner;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/pruner_'.uniqid();
    $this->files = new Filesystem;
    $this->pruner = new DirectoryPruner($this->files);
    $this->files->ensureDirectoryExists($this->root.'/Livewire');
});

afterEach(function () {
    $this->files->deleteDirectory($this->root);
});

test('it prunes empty parent directories up to but not including the boundary', function () {
    $this->files->ensureDirectoryExists($this->root.'/Livewire/Admin/Reports');

    $this->pruner->pruneEmptyParentDirectories($this->root.'/Livewire/Admin/Reports/Table.php', $this->root.'/Livewire');

    expect(is_dir($this->root.'/Livewire/Admin/Reports'))->toBeFalse()
        ->and(is_dir($this->root.'/Livewire/Admin'))->toBeFalse()
        ->and(is_dir($this->root.'/Livewire'))->toBeTrue();
});

test('it stops at the first non-empty directory', function () {
    $this->files->ensureDirectoryExists($this->root.'/Livewire/Admin/Reports');
    $this->files->put($this->root.'/Livewire/Admin/Other.php', '<?php');

    $this->pruner->pruneEmptyParentDirectories($this->root.'/Livewire/Admin/Reports/Table.php', $this->root.'/Livewire');

    expect(is_dir($this->root.'/Livewire/Admin/Reports'))->toBeFalse()
        ->and(is_file($this->root.'/Livewire/Admin/Other.php'))->toBeTrue();
});

test('it stops at a directory that still holds an empty subdirectory', function () {
    $this->files->ensureDirectoryExists($this->root.'/Livewire/Admin/Reports');
    $this->files->ensureDirectoryExists($this->root.'/Livewire/Admin/Keep');

    $this->pruner->pruneEmptyParentDirectories($this->root.'/Livewire/Admin/Reports/Table.php', $this->root.'/Livewire');

    expect(is_dir($this->root.'/Livewire/Admin/Reports'))->toBeFalse()
        ->and(is_dir($this->root.'/Livewire/Admin/Keep'))->toBeTrue();
});

test('it leaves the boundary directory in place when everything below it is pruned', function () {
    $this->files->ensureDirectoryExists($this->root.'/Livewire/Admin');

    $this->pruner->pruneEmptyParentDirectories($this->root.'/Livewire/Admin/Table.php', $this->root.'/Livewire');

    expect(is_dir($this->root.'/Livewire'))->toBeTrue()
        ->and(is_dir($this->root.'/Livewire/Admin'))->toBeFalse();
});

test('it prunes nothing when the path does not exist', function () {
    $this->files->ensureDirectoryExists($this->root.'/Livewire/Admin');

    $this->pruner->pruneEmptyParentDirectories($this->root.'/Livewire/Admin/Missing/Table.php', $this->root.'/Livewire');

    expect(is_dir($this->root.'/Livewire/Admin'))->toBeTrue();
});

test('it does nothing when the path is the boundary itself', function () {
    $this->pruner->pruneEmptyParentDirectories($this->root.'/Livewire', $this->root.'/Livewire');

    expect(is_dir($this->root.'/Livewire'))->toBeTrue()
        ->and(is_dir($this->root))->toBeTrue();
});

test('it does nothing when the boundary does not exist', function () {
    $this->files->ensureDirectoryExists($this->root.'/Livewire/Admin');

    $this->pruner->pruneEmptyParentDirectories($this->root.'/Livewire/Admin/Table.php', $this->root.'/Missing');

    expect(is_dir($this->root.'/Livewire/Admin'))->toBeTrue();
});

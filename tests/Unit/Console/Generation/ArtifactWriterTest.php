<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

test('it throws RuntimeException when artifact file already exists on disk', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'artifact_test');
    $writer = new ArtifactWriter(new Filesystem);
    $module = new ModuleDefinition('Blog', 'Studeo\\Blog', '/path/to/Blog');

    try {
        expect(fn () => $writer->ensureDoesNotExist($tempFile, 'Rule', 'ValidCoupon', $module))
            ->toThrow(RuntimeException::class, 'Rule [ValidCoupon] already exists in module [Blog].');
    } finally {
        @unlink($tempFile);
    }
});

test('it does nothing when artifact file does not exist on disk', function () {
    $writer = new ArtifactWriter(new Filesystem);
    $module = new ModuleDefinition('Blog', 'Studeo\\Blog', '/path/to/Blog');

    $writer->ensureDoesNotExist('/non/existent/file.php', 'Rule', 'ValidCoupon', $module);

    expect(true)->toBeTrue();
});

function recordingFilesystem(): Filesystem
{
    return new class extends Filesystem
    {
        /** @var array<int, array{string, array<int, mixed>}> */
        public array $calls = [];

        public function ensureDirectoryExists($path, $mode = 0755, $recursive = true): void
        {
            $this->calls[] = ['ensureDirectoryExists', func_get_args()];
            parent::ensureDirectoryExists($path, $mode, $recursive);
        }

        public function put($path, $contents, $lock = false): int|bool
        {
            $this->calls[] = ['put', func_get_args()];

            return parent::put($path, $contents, $lock);
        }
    };
}

test('it writes a file, creating its directory first, with exactly two filesystem calls', function () {
    $root = sys_get_temp_dir().'/artifact_write_'.uniqid();
    $files = recordingFilesystem();

    try {
        (new ArtifactWriter($files))->write("{$root}/a/b/Thing.php", 'content');

        expect($files->calls)->toBe([
            ['ensureDirectoryExists', ["{$root}/a/b"]],
            ['put', ["{$root}/a/b/Thing.php", 'content']],
        ])->and(file_get_contents("{$root}/a/b/Thing.php"))->toBe('content');
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

test('it overwrites an existing file without checking for it', function () {
    $root = sys_get_temp_dir().'/artifact_write_'.uniqid();
    $files = recordingFilesystem();
    (new Filesystem)->ensureDirectoryExists($root);
    file_put_contents("{$root}/Thing.php", 'old');

    try {
        (new ArtifactWriter($files))->write("{$root}/Thing.php", 'new');

        expect(file_get_contents("{$root}/Thing.php"))->toBe('new')
            ->and(array_column($files->calls, 0))->toBe(['ensureDirectoryExists', 'put']);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

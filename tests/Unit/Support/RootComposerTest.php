<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Support\RootComposer;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->composerPath = base_path('composer.json');
    $this->originalContent = File::exists($this->composerPath)
        ? File::get($this->composerPath)
        : null;

    $this->sampleData = [
        'name' => 'laravel/laravel',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
    ];

    File::put(
        $this->composerPath,
        json_encode($this->sampleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
    );
});

afterEach(function () {
    if ($this->originalContent !== null) {
        File::put($this->composerPath, $this->originalContent);
    } else {
        File::delete($this->composerPath);
    }
});

it('returns the canonical root composer path', function () {
    $rootComposer = app(RootComposer::class);

    expect($rootComposer->path())->toBe($this->composerPath);
});

it('reads and decodes a valid root composer.json', function () {
    $rootComposer = app(RootComposer::class);

    expect($rootComposer->read())->toBe($this->sampleData);
});

it('throws when root composer.json does not exist', function () {
    File::delete($this->composerPath);

    $rootComposer = app(RootComposer::class);

    expect(fn () => $rootComposer->read())
        ->toThrow(RuntimeException::class, "Root composer.json not found: {$this->composerPath}");
});

it('throws when root composer.json is invalid json', function () {
    File::put($this->composerPath, '{ invalid json }');

    $rootComposer = app(RootComposer::class);

    expect(fn () => $rootComposer->read())
        ->toThrow(RuntimeException::class, "Invalid root composer.json: {$this->composerPath}");
});

it('determines if a namespace is installed', function () {
    $rootComposer = app(RootComposer::class);

    expect($rootComposer->isInstalled('App\\'))->toBeTrue()
        ->and($rootComposer->isInstalled('App'))->toBeTrue()
        ->and($rootComposer->isInstalled('Studeo\\Account\\'))->toBeFalse();
});

it('returns installed psr-4 mappings', function () {
    $rootComposer = app(RootComposer::class);

    expect($rootComposer->installedPsr4())->toBe(['App\\' => 'app/']);
});

it('discovers installed module names from root composer psr-4 mappings', function () {
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    $composer['autoload']['psr-4']['Studeo\\Billing\\'] = 'modules/Billing/src/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $rootComposer = app(RootComposer::class);

    expect($rootComposer->installedModules())->toBe(['Account', 'Billing']);
});

it('handles namespace check when autoload or psr-4 is missing', function () {
    File::put($this->composerPath, json_encode(['name' => 'laravel/laravel']));

    $rootComposer = app(RootComposer::class);

    expect($rootComposer->isInstalled('App\\'))->toBeFalse();
});

it('modifies root composer and dumps autoloads successfully', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);

    $rootComposer = new RootComposer(app(Filesystem::class), $composerMock);

    $changed = $rootComposer->modify(function (array $data): array {
        $data['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';

        return $data;
    });

    expect($changed)->toBeTrue()
        ->and($rootComposer->isUpdated())->toBeTrue();

    $content = json_decode(File::get($this->composerPath), true);
    expect($content['autoload']['psr-4']['Studeo\\Account\\'])->toBe('modules/Account/');
});

it('does not write to disk or dump autoloads if modifier makes no changes', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();

    $rootComposer = new RootComposer(app(Filesystem::class), $composerMock);
    $beforeContent = File::get($this->composerPath);

    $changed = $rootComposer->modify(function (array $data): array {
        return $data;
    });

    expect($changed)->toBeFalse()
        ->and($rootComposer->isUpdated())->toBeFalse()
        ->and(File::get($this->composerPath))->toBe($beforeContent);
});

it('rolls back root composer and resets updated flag if dumpAutoloads returns non-zero exit code', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(1);

    $rootComposer = new RootComposer(app(Filesystem::class), $composerMock);
    $beforeContent = File::get($this->composerPath);

    expect(function () use ($rootComposer) {
        $rootComposer->modify(function (array $data): array {
            $data['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';

            return $data;
        });
    })->toThrow(RuntimeException::class, 'Composer dump-autoload failed with exit code [1].');

    expect($rootComposer->isUpdated())->toBeFalse()
        ->and(File::get($this->composerPath))->toBe($beforeContent);
});

it('rolls back root composer and resets updated flag if dumpAutoloads throws an exception', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andThrow(new RuntimeException('Process execution failed.'));

    $rootComposer = new RootComposer(app(Filesystem::class), $composerMock);
    $beforeContent = File::get($this->composerPath);

    expect(function () use ($rootComposer) {
        $rootComposer->modify(function (array $data): array {
            $data['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';

            return $data;
        });
    })->toThrow(RuntimeException::class, 'Process execution failed.');

    expect($rootComposer->isUpdated())->toBeFalse()
        ->and(File::get($this->composerPath))->toBe($beforeContent);
});

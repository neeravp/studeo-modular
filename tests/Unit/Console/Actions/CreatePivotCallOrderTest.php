<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreatePivotAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\PivotGenerationOptions;
use Studeo\Modular\Tests\Support\PivotCases;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->accountModule = new ModuleDefinition('Account', 'Studeo\\Account', $this->rootModulesPath.'/Account');

    app(CreateModuleAction::class)->execute($this->accountModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * The log with the migration's timestamp replaced, because it is the clock.
 *
 * @param  array<int, string>  $calls
 * @return array<int, string>
 */
function withoutMigrationTimestamps(array $calls): array
{
    return preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}_/', '<timestamp>_', $calls);
}

it('asks the Filesystem for the same calls, in the same order, for every pivot variant', function (string $fixture, array $models, string $first, ?string $second, PivotGenerationOptions $options, ?string $message) {
    seedPivotModels($this->accountModule, $models);

    $files = orderedFilesystem();
    $create = fn () => app(CreatePivotAction::class)->execute($this->accountModule, $first, $second, $options);

    if ($message === null) {
        $create();
    } else {
        expect($create)->toThrow(RuntimeException::class, $message);
    }

    expect(withoutMigrationTimestamps($files->calls))->toBe(callFixture($fixture, 'pivot-calls'));
})->with(PivotCases::calls());

it('refuses a module that is not on disk before touching anything else', function () {
    $ghost = new ModuleDefinition('Ghost', 'Studeo\\Ghost', $this->rootModulesPath.'/Ghost');
    $files = orderedFilesystem();

    expect(fn () => app(CreatePivotAction::class)->execute($ghost, 'Flight', 'Passenger'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghost->path}].")
        ->and($files->calls)->toBe(callFixture('error-module-missing', 'pivot-calls'));
});

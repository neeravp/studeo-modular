<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * @param  int  $count  how many unique or foreign-key fields the schema holds
 * @return array<int, array<string, mixed>> the module:check findings for the generated Create Action
 */
function stepCountFindings(object $test, int $count): array
{
    $fields = array_map(fn (int $number): string => $number % 2 === 0 ? "code{$number}:string:unique" : "ref{$number}_id:foreignId:constrained(users)", range(1, $count));

    blogModule($test, 'Studeo');
    expect(Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => implode(',', $fields)]))->toBe(0);
    Artisan::call('module:check', ['module' => 'Blog', '--format' => 'json']);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('passes StepCount with eight guard calls in the transaction closure', function () {
    expect(stepCountFindings($this, 8))->toBe([]);
});

it('passes StepCount with nine guard checks, which are grouped by kind', function () {
    expect(stepCountFindings($this, 9))->toBe([]);
});

<?php

use Illuminate\Support\Facades\Artisan;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;

uses(TestCase::class);

it('no longer registers module:request', function () {
    expect(array_keys(Artisan::all()))->not->toContain('module:request');

    $this->artisan('module:request', ['name' => 'StorePostRequest'])->run();
})->throws(CommandNotFoundException::class);

it('has no FormRequest generator left in the package', function () {
    $root = dirname(__DIR__, 2);

    expect(class_exists('Studeo\\Modular\\Console\\ModuleRequestCommand'))->toBeFalse()
        ->and(class_exists('Studeo\\Modular\\Console\\Actions\\CreateRequestAction'))->toBeFalse()
        ->and(class_exists('Studeo\\Modular\\DataObjects\\RequestGenerationOptions'))->toBeFalse()
        ->and($root.'/Console/stubs/Request.php.stub')->not->toBeFile();
});

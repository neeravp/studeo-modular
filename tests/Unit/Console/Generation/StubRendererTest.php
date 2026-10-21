<?php

use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

test('it resolves absolute path to stub in console stubs directory', function () {
    $path = (new StubRenderer)->path('action.stub');

    expect($path)->toBeString()
        ->and(file_exists($path))->toBeTrue()
        ->and(basename($path))->toBe('action.stub');
});

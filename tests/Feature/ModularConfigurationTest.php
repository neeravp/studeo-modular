<?php

use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

test('modular root path is inside the test application', function () {
    expect(config('modular.root_path'))
        ->toBe(base_path('modules'));
});

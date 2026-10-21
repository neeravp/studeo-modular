<?php

use Studeo\Modular\DataObjects\DtoKind;

it('finds the kind from the case-sensitive suffix of a class name', function (string $name, ?string $expected): void {
    expect(DtoKind::forClassName($name)?->value)->toBe($expected);
})->with([
    'input' => ['CreatePostInput', 'Input'],
    'output' => ['PostData', 'Data'],
    'lower case start' => ['postInput', 'Input'],
    'input before data' => ['DataInput', 'Input'],
    'data after input' => ['InputData', 'Data'],
    'one character before the suffix' => ['XInput', 'Input'],
    'only the input suffix' => ['Input', null],
    'only the data suffix' => ['Data', null],
    'empty' => ['', null],
    'lower case suffix' => ['xinput', null],
    'upper case suffix' => ['PostDATA', null],
    'plural' => ['PostInputs', null],
    'neither suffix' => ['Post', null],
    'dto suffix' => ['PostDto', null],
]);

it('maps each kind to its stub', function (): void {
    expect(DtoKind::Input->stub())->toBe('dto.input.stub')
        ->and(DtoKind::Output->stub())->toBe('dto.output.stub');
});

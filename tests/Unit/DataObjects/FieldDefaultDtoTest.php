<?php

use Studeo\Modular\DataObjects\FieldDefault;

it('renders literals for a DTO as constant expressions', function (FieldDefault $default, string $expected) {
    expect($default->dto())->toBe($expected);
})->with([
    'int' => [new FieldDefault(0, 'int'), '0'],
    'bool true' => [new FieldDefault(true, 'bool'), 'true'],
    'bool false' => [new FieldDefault(false, 'bool'), 'false'],
    'string' => [new FieldDefault("it's", 'string'), "'it\\'s'"],
    'decimal stays a string' => [new FieldDefault('12.50', 'decimal'), "'12.50'"],
    'float keeps its text' => [new FieldDefault('1.5', 'float'), '1.5'],
    'null' => [new FieldDefault(null, 'null'), 'null'],
]);

it('renders an enum default as the enum case', function () {
    expect((new FieldDefault('draft', 'enum'))->dto('PostStatus', 'Draft'))->toBe('PostStatus::Draft');
});

it('refuses to render an enum default without its case', function () {
    (new FieldDefault('draft', 'enum'))->dto();
})->throws(LogicException::class, 'case');

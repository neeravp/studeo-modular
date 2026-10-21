<?php

use Studeo\Modular\Console\Actions\ParseStructuredFieldAction;

beforeEach(function () {
    $this->action = new ParseStructuredFieldAction;
});

it('parses string config shorthand', function () {
    $field = $this->action->execute('title', 'string:nullable:unique');

    expect($field->name)->toBe('title')
        ->and($field->type)->toBe('string')
        ->and($field->isNullable())->toBeTrue()
        ->and($field->isUnique())->toBeTrue();
});

it('parses array config with explicit attributes', function () {
    $field = $this->action->execute('status', [
        'type' => 'enum',
        'enum' => 'PostStatus',
        'default' => 'draft',
    ]);

    expect($field->name)->toBe('status')
        ->and($field->type)->toBe('enum')
        ->and($field->enum)->toBe('PostStatus')
        ->and($field->default->value)->toBe('draft');
});

it('parses inline and explicit type parameters via narrative pipeline', function () {
    $inlineField = $this->action->execute('price', [
        'type' => 'decimal(10,2)',
        'nullable' => true,
    ]);

    $explicitPrecisionField = $this->action->execute('rate', [
        'type' => 'decimal',
        'precision' => 8,
        'scale' => 4,
    ]);

    $explicitLengthField = $this->action->execute('code', [
        'type' => 'string',
        'length' => 5,
    ]);

    $explicitArgsField = $this->action->execute('coords', [
        'type' => 'decimal',
        'type_arguments' => [12, 6],
    ]);

    expect($inlineField->type)->toBe('decimal')
        ->and($inlineField->typeArguments)->toBe(['10', '2'])
        ->and($explicitPrecisionField->type)->toBe('decimal')
        ->and($explicitPrecisionField->typeArguments)->toBe(['8', '4'])
        ->and($explicitLengthField->type)->toBe('string')
        ->and($explicitLengthField->typeArguments)->toBe(['5'])
        ->and($explicitArgsField->type)->toBe('decimal')
        ->and($explicitArgsField->typeArguments)->toBe(['12', '6']);
});

<?php

use Studeo\Modular\DataObjects\FieldDefinition;

it('generates phpDocProperty correctly for various types', function (): void {
    $title = new FieldDefinition(name: 'title', type: 'string');
    $price = new FieldDefinition(name: 'price', type: 'decimal', modifiers: ['nullable']);
    $count = new FieldDefinition(name: 'count', type: 'integer');

    expect($title->phpDocProperty())->toBe('@property string $title')
        ->and($price->phpDocProperty())->toBe('@property ?string $price')
        ->and($count->phpDocProperty())->toBe('@property int $count');
});

it('correctly reports guarded and fillable status', function (): void {
    $id = new FieldDefinition(name: 'id', type: 'id');
    $uuid = new FieldDefinition(name: 'uuid', type: 'string', modifiers: ['guarded']);
    $title = new FieldDefinition(name: 'title', type: 'string');
    $explicitGuarded = new FieldDefinition(name: 'token', type: 'string', fillable: false);
    $explicitFillable = new FieldDefinition(name: 'token', type: 'string', fillable: true);

    expect($id->isFillable())->toBeFalse()
        ->and($uuid->isGuarded())->toBeTrue()
        ->and($uuid->isFillable())->toBeFalse()
        ->and($title->isGuarded())->toBeFalse()
        ->and($title->isFillable())->toBeTrue()
        ->and($explicitGuarded->isFillable())->toBeFalse()
        ->and($explicitFillable->isFillable())->toBeTrue();
});

it('generates migration definition with type parameters', function (): void {
    $amount = new FieldDefinition(name: 'amount', type: 'decimal', typeArguments: ['8', '2']);
    $code = new FieldDefinition(name: 'code', type: 'string', typeArguments: ['5']);
    $shortCode = new FieldDefinition(name: 'short_code', type: 'char', typeArguments: ['4'], modifiers: ['nullable']);

    expect($amount->migrationDefinition())->toBe("\$table->decimal('amount', 8, 2);")
        ->and($code->migrationDefinition())->toBe("\$table->string('code', 5);")
        ->and($shortCode->migrationDefinition())->toBe("\$table->char('short_code', 4)->nullable();");
});

it('resolves decimal cast with custom scale from type arguments', function (): void {
    $amount = new FieldDefinition(name: 'amount', type: 'decimal', typeArguments: ['10', '4']);
    $defaultDecimal = new FieldDefinition(name: 'total', type: 'decimal');

    expect($amount->castType())->toBe('decimal:4')
        ->and($defaultDecimal->castType())->toBe('decimal:2');
});

it('correctly determines if a modifier exists via hasModifier', function (): void {
    $field = new FieldDefinition(name: 'status', type: 'string', modifiers: ['nullable', 'default(active)']);

    expect($field->hasModifier('nullable'))->toBeTrue()
        ->and($field->hasModifier('default'))->toBeTrue()
        ->and($field->hasModifier('unique'))->toBeFalse();
});

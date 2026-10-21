<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\FieldModifier;
use Studeo\Modular\DataObjects\ModifierKind;

it('names the field and an empty enum in the unresolved message when the enum is null, as today', function (): void {
    (new FieldDefinition(name: 'x', type: 'string'))->enumClass();
})->throws(LogicException::class, 'Enum [] on field [x] is not resolved to a module; call forModule() first.');

it('names the short enum in the unresolved message, as today', function (): void {
    (new FieldDefinition(name: 'x', type: 'enum', enum: 'PostStatus'))->enumClass();
})->throws(LogicException::class, 'Enum [PostStatus] on field [x] is not resolved to a module; call forModule() first.');

it('treats an empty string enum as unresolved, as today', function (): void {
    (new FieldDefinition(name: 'x', type: 'enum', enum: ''))->enumClass();
})->throws(LogicException::class, 'Enum [] on field [x]');

it('strips every leading backslash from a qualified enum class, as today', function (): void {
    expect((new FieldDefinition(name: 'x', type: 'enum', enum: '\\A\\B'))->enumClass())->toBe('A\\B')
        ->and((new FieldDefinition(name: 'x', type: 'enum', enum: '\\\\A\\B'))->enumClass())->toBe('A\\B')
        ->and((new FieldDefinition(name: 'x', type: 'enum', enum: 'A\\B'))->enumClass())->toBe('A\\B');
});

it('returns the same instance from forModule when the enum is null, as today', function (): void {
    $field = new FieldDefinition(name: 'x', type: 'string');

    expect($field->forModule('Studeo\\Blog'))->toBe($field);
});

it('returns the same instance from forModule when the enum is already qualified, as today', function (): void {
    $field = new FieldDefinition(name: 'x', type: 'enum', enum: 'Other\\Enums\\Kind');

    expect($field->forModule('Studeo\\Blog'))->toBe($field)
        ->and($field->forModule('Studeo\\Blog')->enum)->toBe('Other\\Enums\\Kind');
});

it('passes every other constructor value through when forModule qualifies a short enum, as today', function (): void {
    $nullable = new FieldModifier(ModifierKind::Nullable);
    $field = new FieldDefinition('status', 'enum', [$nullable, 'index'], 'PostStatus', true, ' custom ', ['1', '2']);

    $resolved = $field->forModule('Studeo\\Blog');

    expect($resolved)->not->toBe($field)
        ->and($resolved->enum)->toBe('Studeo\\Blog\\Enums\\PostStatus')
        ->and($resolved->name)->toBe('status')
        ->and($resolved->type)->toBe('enum')
        ->and($resolved->fillable)->toBeTrue()
        ->and($resolved->cast)->toBe(' custom ')
        ->and($resolved->typeArguments)->toBe(['1', '2'])
        ->and($resolved->modifiers[0])->toBe($nullable)
        ->and($resolved->modifiers[1]->kind)->toBe(ModifierKind::Index)
        ->and($resolved->enumClass())->toBe('Studeo\\Blog\\Enums\\PostStatus')
        ->and($field->enum)->toBe('PostStatus');
});

it('builds a namespace ending in a backslash from an empty string enum in forModule, as today', function (): void {
    $field = new FieldDefinition(name: 'x', type: 'enum', enum: '');

    expect($field->forModule('N')->enum)->toBe('N\\Enums\\');
});

it('returns the first modifier of a kind when there are duplicates, as today', function (): void {
    $field = new FieldDefinition(name: 'x', type: 'string', modifiers: ['default(1)', 'nullable', 'default(2)']);

    expect($field->modifier(ModifierKind::DefaultValue)->argument)->toBe('1')
        ->and($field->modifier(ModifierKind::Nullable)->kind)->toBe(ModifierKind::Nullable);
});

it('returns null from modifier when the kind is absent, as today', function (): void {
    $field = new FieldDefinition(name: 'x', type: 'string', modifiers: ['nullable']);

    expect($field->modifier(ModifierKind::Unique))->toBeNull()
        ->and((new FieldDefinition(name: 'y'))->modifier(ModifierKind::Nullable))->toBeNull();
});

it('finds a modifier by name case-insensitively and through the cascade alias', function (): void {
    $field = new FieldDefinition(name: 'x', type: 'foreignId', modifiers: ['nullable', 'cascadeOnDelete']);

    expect($field->hasModifier('Nullable'))->toBeTrue()
        ->and($field->hasModifier('NULLABLE'))->toBeTrue()
        ->and($field->hasModifier('cascade'))->toBeTrue()
        ->and($field->hasModifier('CASCADE'))->toBeTrue()
        ->and($field->hasModifier('cascadeOnDelete'))->toBeTrue()
        ->and($field->hasModifier('cascadeondelete'))->toBeTrue();
});

it('reports false from hasModifier for unknown, empty and padded names, as today (no trim)', function (): void {
    $field = new FieldDefinition(name: 'x', type: 'string', modifiers: ['nullable']);

    expect($field->hasModifier('bogus'))->toBeFalse()
        ->and($field->hasModifier(''))->toBeFalse()
        ->and($field->hasModifier(' nullable'))->toBeFalse()
        ->and($field->hasModifier('nullable '))->toBeFalse()
        ->and($field->hasModifier('unique'))->toBeFalse();
});

<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\Support\LivewireClassResolver;

test('it maps each field type to plain Livewire state', function (FieldDefinition $field, string $type, string $default) {
    expect(LivewireClassResolver::resolvePropertyType($field))->toBe($type)
        ->and(LivewireClassResolver::resolvePropertyDefault($field))->toBe($default);
})->with([
    'string' => [new FieldDefinition(name: 'title', type: 'string'), 'string', "''"],
    'text' => [new FieldDefinition(name: 'content', type: 'text'), 'string', "''"],
    'boolean' => [new FieldDefinition(name: 'is_featured', type: 'boolean'), 'bool', 'false'],
    'integer is nullable for empty input' => [new FieldDefinition(name: 'views', type: 'integer'), '?int', 'null'],
    'decimal is a nullable string' => [new FieldDefinition(name: 'price', type: 'decimal', typeArguments: ['8', '2']), '?string', 'null'],
    'enum is a string' => [new FieldDefinition(name: 'status', type: 'enum', enum: 'Studeo\\Blog\\Enums\\PostStatus'), 'string', "''"],
    'foreign key is a nullable int' => [new FieldDefinition(name: 'category_id', type: 'foreignId'), '?int', 'null'],
    'json is the JSON text' => [new FieldDefinition(name: 'meta', type: 'json'), 'string', "''"],
    'nullable jsonb is nullable JSON text' => [new FieldDefinition(name: 'meta', type: 'jsonb', modifiers: ['nullable']), '?string', 'null'],
    'nullable string' => [new FieldDefinition(name: 'subtitle', type: 'string', modifiers: ['nullable']), '?string', 'null'],
]);

test('it resolves property type and defaults correctly', function () {
    $boolField = new FieldDefinition(name: 'active', type: 'boolean');
    $intField = new FieldDefinition(name: 'count', type: 'integer');
    $nullableField = new FieldDefinition(name: 'bio', type: 'text', modifiers: ['nullable']);

    expect(LivewireClassResolver::resolvePropertyType($boolField))->toBe('bool')
        ->and(LivewireClassResolver::resolvePropertyDefault($boolField))->toBe('false')
        ->and(LivewireClassResolver::resolvePropertyType($intField))->toBe('?int')
        ->and(LivewireClassResolver::resolvePropertyDefault($intField))->toBe('null')
        ->and(LivewireClassResolver::resolvePropertyType($nullableField))->toBe('?string')
        ->and(LivewireClassResolver::resolvePropertyDefault($nullableField))->toBe('null');
});

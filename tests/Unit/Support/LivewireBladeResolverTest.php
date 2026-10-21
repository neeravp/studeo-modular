<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\LivewireBladeResolver;

test('it renders nothing when there are no fields', function () {
    expect(LivewireBladeResolver::resolve([]))->toBe('');
});

test('it prefixes every wire:model and @error key with the state key of a Form', function () {
    $html = LivewireBladeResolver::resolve([new FieldDefinition(name: 'title', type: 'string'), new FieldDefinition(name: 'body', type: 'text')], 'form.');

    expect($html)->toContain('wire:model="form.title"')->toContain("@error('form.title')")->toContain('wire:model="form.body"')
        ->not->toContain('wire:model="title"')->not->toContain("@error('title')");
});

test('it renders form controls for text, textarea, select, checkbox, and number with wire:model', function () {
    $schema = new SchemaDefinition(
        name: 'Article',
        table: 'articles',
        fields: [
            'title' => new FieldDefinition(name: 'title', type: 'string'),
            'body' => new FieldDefinition(name: 'body', type: 'text'),
            'email' => new FieldDefinition(name: 'email', type: 'string'),
            'rating' => new FieldDefinition(name: 'rating', type: 'integer'),
            'price' => new FieldDefinition(name: 'price', type: 'decimal'),
            'is_published' => new FieldDefinition(name: 'is_published', type: 'boolean'),
            'status' => new FieldDefinition(name: 'status', type: 'enum', enum: 'Studeo\\Blog\\Enums\\PostStatus'),
            'published_at' => new FieldDefinition(name: 'published_at', type: 'date'),
            'author_id' => new FieldDefinition(name: 'author_id', type: 'foreignId'),
        ]
    );

    $fields = LivewireBladeResolver::resolve(array_values($schema->fields));

    // Title text input
    expect($fields)
        ->toContain('<label for="title" class="block text-sm font-medium text-gray-700">Title</label>')
        ->toContain('wire:model="title"')
        ->toContain('type="text"')
        ->toContain("@error('title')")
        ->toContain('{{ $message }}');

    // Body textarea
    expect($fields)
        ->toContain('<label for="body" class="block text-sm font-medium text-gray-700">Body</label>')
        ->toContain('wire:model="body"')
        ->toContain('<textarea')
        ->toContain('rows="4"');

    // Email input
    expect($fields)
        ->toContain('wire:model="email"')
        ->toContain('type="email"');

    // Rating number input
    expect($fields)
        ->toContain('wire:model="rating"')
        ->toContain('type="number"');

    // Price number input with step="any"
    expect($fields)
        ->toContain('wire:model="price"')
        ->toContain('type="number" step="any"');

    // Checkbox input
    expect($fields)
        ->toContain('wire:model="is_published"')
        ->toContain('type="checkbox"')
        ->toContain('<label for="is_published" class="ml-2 block text-sm text-gray-900">Is Published</label>');

    // Select with options
    expect($fields)
        ->toContain('<select')
        ->toContain('wire:model="status"')
        ->toContain('@foreach (\\Studeo\\Blog\\Enums\\PostStatus::cases() as $case)')
        ->toContain('<option value="{{ $case->value }}">{{ $case->name }}</option>')
        ->not->toContain('<option value="draft">');

    // Date input
    expect($fields)
        ->toContain('wire:model="published_at"')
        ->toContain('type="date"');

    // Foreign key label
    expect($fields)
        ->toContain('<label for="author_id" class="block text-sm font-medium text-gray-700">Author</label>')
        ->toContain('wire:model="author_id"');
});

test('it resolves field labels accurately', function () {
    expect(LivewireBladeResolver::resolveFieldLabel(new FieldDefinition(name: 'first_name', type: 'string')))
        ->toBe('First Name')
        ->and(LivewireBladeResolver::resolveFieldLabel(new FieldDefinition(name: 'user_id', type: 'foreignId')))
        ->toBe('User')
        ->and(LivewireBladeResolver::resolveFieldLabel(new FieldDefinition(name: 'id', type: 'id')))
        ->toBe('Id');
});

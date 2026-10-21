<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\Support\VueFormResolver;

test('it renders nothing for no fields', function () {
    expect(VueFormResolver::resolveFormState([]))->toBe('')
        ->and(VueFormResolver::resolveFormType([]))->toBe('')
        ->and(VueFormResolver::resolveFormFields([]))->toBe('');
});

test('it resolves useForm initial state for different field types', function () {
    $fields = [
        'title' => new FieldDefinition(name: 'title', type: 'string'),
        'content' => new FieldDefinition(name: 'content', type: 'text'),
        'is_featured' => new FieldDefinition(name: 'is_featured', type: 'boolean'),
        'views' => new FieldDefinition(name: 'views', type: 'integer'),
        'price' => new FieldDefinition(name: 'price', type: 'decimal', typeArguments: ['8', '2']),
        'status' => new FieldDefinition(name: 'status', type: 'enum', enum: 'Studeo\\Blog\\Enums\\PostStatus'),
        'category_id' => new FieldDefinition(name: 'category_id', type: 'foreignId'),
        'meta' => new FieldDefinition(name: 'meta', type: 'json'),
    ];

    $state = VueFormResolver::resolveFormState($fields);

    expect($state)
        ->toContain("title: '',")
        ->toContain("content: '',")
        ->toContain('is_featured: false,')
        ->toContain('views: null,')
        ->toContain('price: null,')
        ->toContain("status: '',")
        ->toContain('category_id: null,')
        ->toContain("meta: '',");
});

test('it renders form controls for text, textarea, select, checkbox, and number', function () {
    $fields = [
        'title' => new FieldDefinition(name: 'title', type: 'string'),
        'body' => new FieldDefinition(name: 'body', type: 'text'),
        'email' => new FieldDefinition(name: 'email', type: 'string'),
        'password' => new FieldDefinition(name: 'password', type: 'string'),
        'rating' => new FieldDefinition(name: 'rating', type: 'integer'),
        'price' => new FieldDefinition(name: 'price', type: 'decimal'),
        'is_published' => new FieldDefinition(name: 'is_published', type: 'boolean'),
        'status' => new FieldDefinition(name: 'status', type: 'enum', enum: 'Studeo\\Blog\\Enums\\PostStatus'),
        'published_at' => new FieldDefinition(name: 'published_at', type: 'date'),
        'author_id' => new FieldDefinition(name: 'author_id', type: 'foreignId'),
    ];

    $markup = VueFormResolver::resolveFormFields($fields);

    // Title text input
    expect($markup)
        ->toContain('<label for="title" class="block text-sm font-medium text-gray-700">Title</label>')
        ->toContain('v-model="form.title"')
        ->toContain('type="text"')
        ->toContain('v-if="form.errors.title"');

    // Body textarea
    expect($markup)
        ->toContain('<label for="body" class="block text-sm font-medium text-gray-700">Body</label>')
        ->toContain('v-model="form.body"')
        ->toContain('<textarea')
        ->toContain('rows="4"');

    // Email input
    expect($markup)
        ->toContain('v-model="form.email"')
        ->toContain('type="email"');

    // Password input
    expect($markup)
        ->toContain('v-model="form.password"')
        ->toContain('type="password"');

    // Rating number input
    expect($markup)
        ->toContain('v-model="form.rating"')
        ->toContain('type="number"');

    // Price number input with step="any"
    expect($markup)
        ->toContain('v-model="form.price"')
        ->toContain('type="number" step="any"');

    // Checkbox input
    expect($markup)
        ->toContain('v-model="form.is_published"')
        ->toContain('type="checkbox"')
        ->toContain('<label for="is_published" class="ml-2 block text-sm text-gray-900">Is Published</label>');

    // An enum's cases live in PHP, so the form holds a plain string and renders a text input
    expect($markup)
        ->toContain('v-model="form.status"')
        ->not->toContain('<option value="draft">');

    // Date input
    expect($markup)
        ->toContain('v-model="form.published_at"')
        ->toContain('type="date"');

    // Foreign key label
    expect($markup)
        ->toContain('<label for="author_id" class="block text-sm font-medium text-gray-700">Author</label>')
        ->toContain('v-model="form.author_id"');
});

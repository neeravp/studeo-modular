<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\Support\MigrationColumnResolver;

it('resolves standard column blueprint statements', function (): void {
    $field = new FieldDefinition(name: 'title', type: 'string');

    expect(MigrationColumnResolver::resolve($field))->toBe("\$table->string('title');");
});

it('resolves id columns properly for primary and custom names', function (): void {
    $primaryId = new FieldDefinition(name: 'id', type: 'id');
    $customId = new FieldDefinition(name: 'order_id', type: 'id');

    expect(MigrationColumnResolver::resolve($primaryId))->toBe('$table->id();')
        ->and(MigrationColumnResolver::resolve($customId))->toBe("\$table->id('order_id');");
});

it('resolves enum columns as a short indexed string, never a database enum', function (): void {
    $field = new FieldDefinition(name: 'status', type: 'enum', enum: 'Studeo\\Blog\\Enums\\PostStatus');
    $nullable = new FieldDefinition(name: 'state', type: 'enum', modifiers: ['nullable'], enum: 'Studeo\\Blog\\Enums\\PostStatus');

    expect(MigrationColumnResolver::resolve($field))->toBe("\$table->string('status', 30)->index();")
        ->and(MigrationColumnResolver::resolve($nullable))->toBe("\$table->string('state', 30)->index()->nullable();");
});

it('resolves parameterized types with arguments', function (): void {
    $price = new FieldDefinition(name: 'price', type: 'decimal', typeArguments: ['8', '2']);
    $code = new FieldDefinition(name: 'code', type: 'string', typeArguments: ['10']);

    expect(MigrationColumnResolver::resolve($price))->toBe("\$table->decimal('price', 8, 2);")
        ->and(MigrationColumnResolver::resolve($code))->toBe("\$table->string('code', 10);");
});

it('applies and chains migration modifiers while ignoring model modifiers', function (): void {
    $field = new FieldDefinition(
        name: 'user_id',
        type: 'foreignId',
        modifiers: ['nullable', 'constrained', 'cascadeOnDelete', 'guarded', 'fillable'],
    );

    expect(MigrationColumnResolver::resolve($field))
        ->toBe("\$table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();");
});

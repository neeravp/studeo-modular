<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\FieldModifier;
use Studeo\Modular\DataObjects\ModifierKind;

it('parses a modifier token into its kind and argument', function (string $token, ModifierKind $kind, ?string $argument, string $canonical) {
    $modifier = FieldModifier::parse($token);

    expect($modifier->kind)->toBe($kind)
        ->and($modifier->argument)->toBe($argument)
        ->and($modifier->token())->toBe($canonical);
})->with([
    'flag' => ['nullable', ModifierKind::Nullable, null, 'nullable'],
    'case insensitive' => ['Nullable', ModifierKind::Nullable, null, 'nullable'],
    'default keeps its quotes' => ["default('two words')", ModifierKind::DefaultValue, "'two words'", "default('two words')"],
    'default number' => ['default(5)', ModifierKind::DefaultValue, '5', 'default(5)'],
    'constrained table is unquoted' => ["constrained('users')", ModifierKind::Constrained, 'users', 'constrained(users)'],
    'bare constrained' => ['constrained', ModifierKind::Constrained, null, 'constrained'],
    'cascade short form' => ['cascade', ModifierKind::CascadeOnDelete, null, 'cascadeOnDelete'],
    'cast with a colon' => ['cast(decimal:2)', ModifierKind::Cast, 'decimal:2', 'cast(decimal:2)'],
    'fillable false' => ['fillable(false)', ModifierKind::Fillable, 'false', 'fillable(false)'],
    'context' => ['context', ModifierKind::Context, null, 'context'],
]);

it('returns null for a token that is not a known modifier', function (string $token) {
    expect(FieldModifier::parse($token))->toBeNull();
})->with(['foo', 'uniq', 'default(', 'unique)', '', 'default (5) extra']);

it('lists the whole closed set of modifiers', function () {
    expect(ModifierKind::names())->toBe([
        'nullable', 'unique', 'index', 'primary', 'unsigned', 'constrained', 'cascadeOnDelete',
        'nullOnDelete', 'default', 'cast', 'hashed', 'guarded', 'fillable', 'context',
    ]);
});

it('holds typed modifiers on a field and accepts tokens for them', function () {
    $field = new FieldDefinition(name: 'owner_id', type: 'foreignId', modifiers: ['nullable', new FieldModifier(ModifierKind::Constrained, 'users'), 'context']);

    expect($field->modifiers)->each->toBeInstanceOf(FieldModifier::class)
        ->and($field->isNullable())->toBeTrue()
        ->and($field->isContext())->toBeTrue()
        ->and($field->modifier(ModifierKind::Constrained)->argument)->toBe('users')
        ->and($field->hasModifier('constrained'))->toBeTrue()
        ->and($field->hasModifier('unique'))->toBeFalse();
});

it('refuses an unknown modifier token on a field', function () {
    new FieldDefinition(name: 'x', modifiers: ['uniq']);
})->throws(InvalidArgumentException::class, '[uniq]');

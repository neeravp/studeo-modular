<?php

use Studeo\Modular\DataObjects\ModifierKind;

it('finds a kind by name case-insensitively, with cascade as the short form', function (string $name, ?string $expected): void {
    expect(ModifierKind::fromName($name)?->value)->toBe($expected);
})->with([
    'exact' => ['nullable', 'nullable'],
    'upper' => ['NULLABLE', 'nullable'],
    'title' => ['Nullable', 'nullable'],
    'camel as written' => ['cascadeOnDelete', 'cascadeOnDelete'],
    'camel lower' => ['cascadeondelete', 'cascadeOnDelete'],
    'camel upper' => ['CASCADEONDELETE', 'cascadeOnDelete'],
    'alias' => ['cascade', 'cascadeOnDelete'],
    'alias upper' => ['CASCADE', 'cascadeOnDelete'],
    'alias title' => ['Cascade', 'cascadeOnDelete'],
    'default' => ['DEFAULT', 'default'],
    'cast' => ['Cast', 'cast'],
    'null on delete' => ['nullOnDelete', 'nullOnDelete'],
    'empty' => ['', null],
    'leading space' => [' nullable', null],
    'trailing space' => ['nullable ', null],
    'unknown' => ['bogus', null],
    'alias plural' => ['cascades', null],
    'alias with suffix' => ['cascadeOnDeletes', null],
]);

it('finds every kind by its own value', function (): void {
    foreach (ModifierKind::cases() as $kind) {
        expect(ModifierKind::fromName($kind->value))->toBe($kind)
            ->and(ModifierKind::fromName(strtoupper($kind->value)))->toBe($kind);
    }
});

<?php

use Studeo\Modular\DataObjects\ClassReference;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

test('it resolves a model reference unqualified inside module', function () {
    $module = new ModuleDefinition('Blog', 'Studeo\\Blog', '/path/to/Blog');
    $ref = ClassReference::resolve($module, 'Post', 'Models');

    expect($ref->fqcn)->toBe('Studeo\\Blog\\Models\\Post')
        ->and($ref->class)->toBe('Post')
        ->and($ref->namespace)->toBe('Studeo\\Blog\\Models')
        ->and($ref->variable)->toBe('post');
});

test('it resolves an external FQCN model reference', function () {
    $module = new ModuleDefinition('Blog', 'Studeo\\Blog', '/path/to/Blog');
    $ref = ClassReference::resolve($module, '\\App\\Models\\User', 'Models');

    expect($ref->fqcn)->toBe('App\\Models\\User')
        ->and($ref->class)->toBe('User')
        ->and($ref->namespace)->toBe('App\\Models')
        ->and($ref->variable)->toBe('user');
});

test('it resolves custom sub-namespace like Events', function () {
    $module = new ModuleDefinition('Billing', 'Studeo\\Billing', '/path/to/Billing');
    $ref = ClassReference::resolve($module, 'InvoicePaid', 'Events');

    expect($ref->fqcn)->toBe('Studeo\\Billing\\Events\\InvoicePaid')
        ->and($ref->class)->toBe('InvoicePaid')
        ->and($ref->namespace)->toBe('Studeo\\Billing\\Events')
        ->and($ref->variable)->toBe('invoicePaid');
});

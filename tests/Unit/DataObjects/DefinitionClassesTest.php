<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\RelationshipDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\Support\DataObject;

it('keeps definition classes plain final readonly classes, not data objects', function (): void {
    $classes = [FieldDefinition::class, SchemaDefinition::class, RelationshipDefinition::class, VueComponentOptions::class];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        expect(is_subclass_of($class, DataObject::class))->toBeFalse("{$class} extends DataObject")
            ->and($reflection->isFinal())->toBeTrue("{$class} is not final")
            ->and($reflection->isReadOnly())->toBeTrue("{$class} is not readonly");
    }
});

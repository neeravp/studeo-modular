<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\Support\Schema\ForeignKeyTarget;

it('names the table constrained() gives, or the one constrained(x) names', function (string $name, array $modifiers, string $table) {
    expect(ForeignKeyTarget::table(new FieldDefinition($name, 'foreignId', $modifiers)))->toBe($table);
})->with([
    'plural of the column' => ['author_id', [], 'authors'],
    'irregular plural' => ['category_id', ['constrained'], 'categories'],
    'two words' => ['blog_post_id', [], 'blog_posts'],
    'named table' => ['owner_id', ["constrained('users')"], 'users'],
]);

it('recognises foreign key types and constrained fields', function () {
    expect(ForeignKeyTarget::isForeignKey(new FieldDefinition('a_id', 'foreignId')))->toBeTrue()
        ->and(ForeignKeyTarget::isForeignKey(new FieldDefinition('a_id', 'foreignUuid')))->toBeTrue()
        ->and(ForeignKeyTarget::isForeignKey(new FieldDefinition('a', 'string')))->toBeFalse();
});

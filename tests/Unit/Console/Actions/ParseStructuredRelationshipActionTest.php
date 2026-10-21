<?php

use Studeo\Modular\Console\Actions\ParseStructuredRelationshipAction;

beforeEach(function () {
    $this->action = new ParseStructuredRelationshipAction;
});

it('parses string shorthand relationship', function () {
    $rel = $this->action->execute('author', 'belongsTo(User):user_id:id');

    expect($rel->name)->toBe('author')
        ->and($rel->type)->toBe('belongsTo')
        ->and($rel->relatedModel)->toBe('User')
        ->and($rel->foreignKey)->toBe('user_id')
        ->and($rel->ownerKey)->toBe('id');
});

it('parses array config relationship', function () {
    $rel = $this->action->execute('posts', [
        'type' => 'hasMany',
        'model' => 'Post',
        'foreign_key' => 'author_id',
    ]);

    expect($rel->name)->toBe('posts')
        ->and($rel->type)->toBe('hasMany')
        ->and($rel->relatedModel)->toBe('Post')
        ->and($rel->foreignKey)->toBe('author_id');
});

it('throws on invalid configuration type', function () {
    expect(fn () => $this->action->execute('invalid', 12345))
        ->toThrow(InvalidArgumentException::class, 'Invalid relationship configuration for [invalid].');
});

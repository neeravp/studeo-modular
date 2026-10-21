<?php

use Studeo\Modular\Console\Actions\BuildActionPreconditionsAction;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

it('names the table from the schema, else from the Create* action', function () {
    $builder = new BuildActionPreconditionsAction;

    expect($builder->table(new SchemaDefinition(table: 'articles'), 'CreatePostAction'))->toBe('articles')
        ->and($builder->table(new SchemaDefinition, 'Posts/CreateBlogPostAction'))->toBe('blog_posts');
});

it('applies to Create* names only', function (string $name, bool $applies) {
    expect((new BuildActionPreconditionsAction)->applies($name))->toBe($applies);
})->with([['CreatePostAction', true], ['Posts/CreatePostAction', true], ['CreateAction', false], ['RecreatePostAction', false], ['PublishPostAction', false]]);

it('returns no checks when no field is unique or a foreign key', function () {
    $schema = new SchemaDefinition(fields: ['title' => new FieldDefinition('title')]);

    expect((new BuildActionPreconditionsAction)->execute($schema, 'posts'))->toBe(['checks' => '', 'guards' => '', 'notices' => []]);
});

it('builds one private guard per check and one call per guard, in field order', function () {
    $schema = new SchemaDefinition(fields: [
        'slug' => new FieldDefinition('slug', 'string', ['unique']),
        'handle' => new FieldDefinition('handle', 'string', ['nullable', 'unique']),
        'author_id' => new FieldDefinition('author_id', 'foreignId', ['constrained(users)']),
    ]);

    $result = (new BuildActionPreconditionsAction)->execute($schema, 'posts');

    expect($result['checks'])->toBe("            \$this->ensureSlugIsUnique(\$input);\n            \$this->ensureHandleIsUnique(\$input);\n            \$this->ensureAuthorIdExists(\$input);\n")
        ->and($result['guards'])->toContain('private function ensureSlugIsUnique({{ input }} $input): void')
        ->toContain('private function ensureAuthorIdExists({{ input }} $input): void')
        ->and(substr_count($result['guards'], 'if ($input->handle === null) {'))->toBe(1)
        ->and($result['guards'])->not->toContain('&&')->not->toContain('||');
});

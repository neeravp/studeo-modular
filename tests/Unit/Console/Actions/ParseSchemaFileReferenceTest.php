<?php

use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\InvalidSchemaException;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/schema-ref-'.bin2hex(random_bytes(4));
    mkdir($this->path.'/schema', 0777, true);
    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->path);
});

afterEach(function () {
    array_map('unlink', glob("{$this->path}/schema/*") ?: []);
    rmdir($this->path.'/schema');
    rmdir($this->path);
});

function reasonFor(callable $call): string
{
    try {
        $call();
    } catch (InvalidSchemaException $exception) {
        return $exception->getMessage();
    }

    throw new RuntimeException('expected the schema reference to be rejected');
}

it('reads @name as schema/{name}.yaml in the module', function () {
    file_put_contents("{$this->path}/schema/posts.yaml", "table: posts\nfields:\n  title: string\n  views: integer:default(0)\n");

    $schema = (new ParseSchemaAction)->execute('@posts', $this->module);

    expect($schema->table)->toBe('posts')
        ->and(array_keys($schema->fields))->toBe(['title', 'views'])
        ->and($schema->fields['views']->default->value)->toBe(0);
});

it('validates the file it names like any other schema', function () {
    file_put_contents("{$this->path}/schema/posts.yaml", "fields:\n  title: strng\n  id: string\n");

    expect(reasonFor(fn () => (new ParseSchemaAction)->execute('@posts', $this->module)))
        ->toContain('Invalid schema: 2 problems found.')->toContain('fields.title:')->toContain('fields.id:');
});

it('names the expected path when the file is missing', function () {
    $message = reasonFor(fn () => (new ParseSchemaAction)->execute('@missing', $this->module));

    expect($message)->toContain('@missing')->toContain("{$this->path}/schema/missing.yaml");
});

it('needs a module', function () {
    $message = reasonFor(fn () => (new ParseSchemaAction)->execute('@posts'));

    expect($message)->toContain('@posts')->toContain('module')->toContain('schema/posts.yaml');
});

it('rejects a name that is not a table name', function (string $reference) {
    expect(reasonFor(fn () => (new ParseSchemaAction)->execute($reference, $this->module)))->toContain($reference)->toContain('lower snake case');
})->with(['@Bad Name', '@../etc/passwd', '@', '@posts.yaml']);

it('leaves every other form alone', function () {
    file_put_contents("{$this->path}/schema/posts.yaml", "table: posts\nfields:\n  title: string\n");

    expect((new ParseSchemaAction)->execute('title:string', $this->module)->fields)->toHaveKey('title')
        ->and((new ParseSchemaAction)->execute("{$this->path}/schema/posts.yaml", $this->module)->table)->toBe('posts')
        ->and((new ParseSchemaAction)->execute("table: posts\nfields:\n  title: string", $this->module)->table)->toBe('posts');
});

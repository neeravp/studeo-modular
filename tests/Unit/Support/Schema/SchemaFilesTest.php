<?php

use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\Schema\SchemaFiles;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/modular-schema-files-'.bin2hex(random_bytes(4));
    mkdir($this->path.'/schema', 0777, true);
    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->path);
});

afterEach(function () {
    array_map('unlink', glob($this->path.'/schema/*') ?: []);
    rmdir($this->path.'/schema');
    rmdir($this->path);
});

it('derives the table of a model the way module:model does', function () {
    expect(SchemaFiles::tableFor('BlogPost'))->toBe('blog_posts')
        ->and(SchemaFiles::tableFor('Category'))->toBe('categories');
});

it('puts the schema file of a table under the module schema directory, whatever the trailing slash', function () {
    $withSlash = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->path.'/');

    expect(SchemaFiles::path($this->module, 'posts'))->toBe($this->path.'/schema/posts.yaml')
        ->and(SchemaFiles::path($withSlash, 'posts'))->toBe($this->path.'/schema/posts.yaml');
});

it('lists the tables that have a schema file, in name order, and ignores other files', function () {
    foreach (['posts.yaml', 'categories.yaml', 'notes.txt', 'authors.yml'] as $file) {
        file_put_contents($this->path.'/schema/'.$file, "name: x\n");
    }

    expect((new SchemaFiles)->tables($this->module))->toBe(['categories', 'posts']);
});

it('lists no tables for a module without a schema directory', function () {
    $ghost = new ModuleDefinition('Ghost', 'Studeo\\Ghost', sys_get_temp_dir().'/modular-no-such-module-'.bin2hex(random_bytes(4)));

    expect((new SchemaFiles)->tables($ghost))->toBe([])
        ->and((new SchemaFiles)->tables($this->module))->toBe([]);
});

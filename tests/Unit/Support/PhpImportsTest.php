<?php

use Studeo\Modular\Support\PhpImports;

it('names each class by its short name and sorts the imports as Pint does', function () {
    $imports = new PhpImports('Acme\Blog\Models', 'Post');

    expect($imports->name('Illuminate\Database\Eloquent\Model'))->toBe('Model')
        ->and($imports->name('\Carbon\CarbonImmutable'))->toBe('CarbonImmutable')
        ->and($imports->name('Acme\Blog\Database\Factories\PostFactory'))->toBe('PostFactory')
        ->and($imports->name('Illuminate\Database\Eloquent\Model'))->toBe('Model')
        ->and($imports->statements())->toBe("use Acme\Blog\Database\Factories\PostFactory;\nuse Carbon\CarbonImmutable;\nuse Illuminate\Database\Eloquent\Model;");
});

it('imports nothing for a class of the file\'s own namespace', function () {
    $imports = new PhpImports('Acme\Blog\Models', 'Post');

    expect($imports->name('\Acme\Blog\Models\Tag'))->toBe('Tag')
        ->and($imports->statements())->toBe('');
});

it('keeps a class fully qualified when its short name is taken by the file\'s class or an import', function () {
    $imports = new PhpImports('Acme\Blog\Models', 'Model');

    expect($imports->name('Illuminate\Database\Eloquent\Model'))->toBe('\Illuminate\Database\Eloquent\Model')
        ->and($imports->name('Acme\Blog\Enums\Status'))->toBe('Status')
        ->and($imports->name('Acme\Shop\Enums\Status'))->toBe('\Acme\Shop\Enums\Status')
        ->and($imports->statements())->toBe('use Acme\Blog\Enums\Status;');
});

it('names the class of a type, keeping a leading ? and leaving builtin types alone', function () {
    $imports = new PhpImports('Acme\Blog\Models');

    expect($imports->type('?\Carbon\CarbonImmutable'))->toBe('?CarbonImmutable')
        ->and($imports->type('\Acme\Blog\Enums\PostStatus'))->toBe('PostStatus')
        ->and($imports->type('?string'))->toBe('?string')
        ->and($imports->type('array'))->toBe('array')
        ->and($imports->statements())->toBe("use Acme\Blog\Enums\PostStatus;\nuse Carbon\CarbonImmutable;");
});

it('keeps the first of two classes sharing a short name and writes the statements sorted by class name', function () {
    $imports = new PhpImports('Acme\Blog\Actions', 'CreatePost');

    $imports->name('Illuminate\Support\Facades\DB');
    $imports->name('Illuminate\Validation\ValidationException');
    $imports->name('Illuminate\Support\Facades\DB');
    $imports->name('Acme\Shop\Facades\DB');
    $imports->name('\Illuminate\Contracts\Auth\Authenticatable');

    expect($imports->name('Acme\Shop\Facades\DB'))->toBe('\Acme\Shop\Facades\DB')
        ->and($imports->name('Illuminate\Support\Facades\DB'))->toBe('DB')
        ->and($imports->statements())->toBe("use Illuminate\Contracts\Auth\Authenticatable;\nuse Illuminate\Support\Facades\DB;\nuse Illuminate\Validation\ValidationException;");
});

it('registers each class given to add as name would, in the given order', function () {
    $imports = new PhpImports('Acme\Blog\Actions', 'CreatePost');

    $imports->add('Illuminate\Support\Facades\DB', 'Illuminate\Validation\ValidationException', 'Illuminate\Support\Facades\DB', 'Acme\Shop\Facades\DB', '\Illuminate\Contracts\Auth\Authenticatable');

    expect($imports->name('Acme\Shop\Facades\DB'))->toBe('\Acme\Shop\Facades\DB')
        ->and($imports->name('Illuminate\Support\Facades\DB'))->toBe('DB')
        ->and($imports->statements())->toBe("use Illuminate\Contracts\Auth\Authenticatable;\nuse Illuminate\Support\Facades\DB;\nuse Illuminate\Validation\ValidationException;");
});

it('lets the first class given to add win a shared short name', function () {
    $imports = new PhpImports('Acme\Blog\Actions');

    $imports->add('Acme\Shop\Facades\DB', 'Illuminate\Support\Facades\DB');

    expect($imports->name('Acme\Shop\Facades\DB'))->toBe('DB')
        ->and($imports->name('Illuminate\Support\Facades\DB'))->toBe('\Illuminate\Support\Facades\DB')
        ->and($imports->statements())->toBe('use Acme\Shop\Facades\DB;');
});

it('keeps a class given to add fully qualified when its short name is the file\'s own class', function () {
    $imports = new PhpImports('Acme\Blog\Models', 'Model');

    $imports->add('Illuminate\Database\Eloquent\Model', 'Carbon\CarbonImmutable');

    expect($imports->name('Illuminate\Database\Eloquent\Model'))->toBe('\Illuminate\Database\Eloquent\Model')
        ->and($imports->statements())->toBe('use Carbon\CarbonImmutable;');
});

it('imports nothing for a class of the own namespace given to add, and nothing for no classes', function () {
    $imports = new PhpImports('Acme\Blog\Models', 'Post');

    $imports->add();
    $imports->add('\Acme\Blog\Models\Tag');

    expect($imports->statements())->toBe('')
        ->and((new ReflectionMethod($imports, 'add'))->getReturnType()?->getName())->toBe('void');
});

<?php

use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->sanitizer = new NameSanitizer;
});

test('it sanitizes flat class name to studly case and strips slashes', function () {
    expect($this->sanitizer->className('post_model', 'model'))->toBe('PostModel')
        ->and($this->sanitizer->className('/user-profile\\', 'model'))->toBe('UserProfile')
        ->and($this->sanitizer->className('   create_user   ', 'action'))->toBe('CreateUser');
});

test('it appends suffix when missing on flat class name', function () {
    expect($this->sanitizer->className('User', 'controller', 'Controller'))->toBe('UserController')
        ->and($this->sanitizer->className('UserController', 'controller', 'Controller'))->toBe('UserController')
        ->and($this->sanitizer->className('user', 'factory', 'Factory'))->toBe('UserFactory')
        ->and($this->sanitizer->className('user_factory', 'factory', 'Factory'))->toBe('UserFactory')
        ->and($this->sanitizer->className('UserFactory', 'factory', 'Factory'))->toBe('UserFactory');
});

test('it throws on empty or slash-only flat class name with exact message', function () {
    expect(fn () => $this->sanitizer->className('   ///   ', 'controller'))
        ->toThrow(RuntimeException::class, 'Invalid controller name [   ///   ].');

    expect(fn () => $this->sanitizer->className('', 'rule'))
        ->toThrow(RuntimeException::class, 'Invalid rule name [].');
});

test('it sanitizes hierarchical path and normalizes separators to forward slash', function () {
    expect($this->sanitizer->classPath('posts/archive_post', 'action'))->toBe('Posts/ArchivePost')
        ->and($this->sanitizer->classPath('admin\\users\\user_card', 'component'))->toBe('Admin/Users/UserCard')
        ->and($this->sanitizer->classPath('/posts///index/', 'component'))->toBe('Posts/Index');
});

test('it throws on empty or slash-only hierarchical path with exact message', function () {
    expect(fn () => $this->sanitizer->classPath('   ///   ', 'component'))
        ->toThrow(RuntimeException::class, 'Invalid component name [   ///   ].');

    expect(fn () => $this->sanitizer->classPath('\\\\\\', 'DTO'))
        ->toThrow(RuntimeException::class, 'Invalid DTO name [\\\\\\].');
});

test('it refuses a flat class name that starts with a digit, before the suffix is looked at', function (string $name) {
    expect(fn () => $this->sanitizer->className($name, 'factory', 'Factory'))
        ->toThrow(RuntimeException::class, "Invalid factory name [{$name}].");
})->with(['0', '2Fa', ' 0 ', '0_x', '9lives']);

test('it keeps a digit inside a class name', function () {
    expect($this->sanitizer->className('Fa2', 'job'))->toBe('Fa2')
        ->and($this->sanitizer->className('x_0', 'job'))->toBe('X0');
});

test('it gives a studly name with no class rule for what is not a class', function () {
    expect($this->sanitizer->studlyName('0', 'model'))->toBe('0')
        ->and($this->sanitizer->studlyName('post_model', 'model'))->toBe('PostModel')
        ->and(fn () => $this->sanitizer->studlyName('///', 'model'))->toThrow(RuntimeException::class, 'Invalid model name [///].');
});

test('it names the given name when a class name starts with a digit', function () {
    $this->sanitizer->ensureStartsWithLetter('Flight', 'flight', 'pivot');

    expect(fn () => $this->sanitizer->ensureStartsWithLetter('0Flight', 'flight_0', 'pivot'))
        ->toThrow(RuntimeException::class, 'Invalid pivot name [flight_0].');
});

<?php

use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\Support\Schema\CliFieldReader;

beforeEach(function () {
    $this->reader = new CliFieldReader;
});

it('reads a bare name as a string field with nothing else', function () {
    expect($this->reader->readField('title'))->toEqual(new RawField('title'));
});

it('reads the type, then the modifiers in the order they were written', function () {
    expect($this->reader->readField('title:text:nullable:unique'))
        ->toEqual(new RawField('title', 'text', modifiers: ['nullable', 'unique']));
});

it('splits the inner text of the type into its arguments', function () {
    expect($this->reader->readField('price:decimal(8,2)'))
        ->toEqual(new RawField('price', 'decimal', typeArguments: ['8', '2']));
});

it('trims the arguments and drops the empty ones', function () {
    expect($this->reader->readField('price:decimal( 8 , ,2 )')->typeArguments)->toBe(['8', '2']);
});

it('reads an enum reference as the enum and never as arguments', function () {
    expect($this->reader->readField('status:enum(PostStatus):nullable'))
        ->toEqual(new RawField('status', 'enum', modifiers: ['nullable'], enum: 'PostStatus'))
        ->and($this->reader->readField('status:ENUM(PostStatus)'))
        ->toEqual(new RawField('status', 'ENUM', enum: 'PostStatus'));
});

it('falls back to a string type when the type part is empty', function () {
    expect($this->reader->readField('note::nullable'))->toEqual(new RawField('note', 'string', modifiers: ['nullable']));
});

it('trims the name and every part, and drops empty modifiers', function () {
    expect($this->reader->readField(' title : text :: nullable : :unique '))
        ->toEqual(new RawField('title', 'text', modifiers: ['nullable', 'unique']));
});

it('keeps a type without a closed pair of parentheses as the type', function () {
    expect($this->reader->readField('bad:decimal(8,2'))->toEqual(new RawField('bad', 'decimal(8,2'));
});

it('does not split on a colon inside parentheses', function () {
    expect($this->reader->readField('label:string:default(a:b):nullable')->modifiers)->toBe(['default(a:b)', 'nullable']);
});

it('splits a raw type into its name and inner text', function () {
    expect($this->reader->splitType('decimal(8,2)'))->toBe(['decimal', '8,2'])
        ->and($this->reader->splitType('string'))->toBe(['string', null])
        ->and($this->reader->splitType('x()'))->toBe(['x', ''])
        ->and($this->reader->splitType('x( a )'))->toBe(['x', 'a'])
        ->and($this->reader->splitType('a('))->toBe(['a(', null])
        ->and($this->reader->splitType('a(b)c'))->toBe(['a(b)c', null]);
});

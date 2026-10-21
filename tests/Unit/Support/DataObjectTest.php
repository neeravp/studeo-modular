<?php

use Illuminate\Database\Eloquent\Model;
use Studeo\Modular\Support\Attributes\Collection as CollectionOf;
use Studeo\Modular\Support\OutputData;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

final readonly class TestAuthorData extends OutputData
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

final readonly class TestPostData extends OutputData
{
    public function __construct(
        public int $id,
        public string $title,
        public string $authorEmail,
        public ?TestAuthorData $author = null,
        public ?TestStatus $status = TestStatus::Active,
        public array $tags = [],
    ) {}
}

final readonly class TestAuthorListData extends OutputData
{
    /**
     * @param  array<int, TestAuthorData>  $authors
     */
    public function __construct(
        #[CollectionOf(TestAuthorData::class)]
        public array $authors,
    ) {}
}

final readonly class TestSecretData extends OutputData
{
    public function __construct(
        public string $name,
        public string $secret,
    ) {}
}

class TestPostModel extends Model
{
    protected $guarded = [];
}

class TestSecretModel extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret'];
}

test('it creates a DTO from an array with snake_case wire keys', function () {
    $dto = TestPostData::from([
        'id' => 1,
        'title' => 'First Post',
        'author_email' => 'author@example.com',
    ]);

    expect($dto->id)->toBe(1)
        ->and($dto->title)->toBe('First Post')
        ->and($dto->authorEmail)->toBe('author@example.com')
        ->and($dto->author)->toBeNull()
        ->and($dto->status)->toBe(TestStatus::Active)
        ->and($dto->tags)->toBe([]);
});

test('it maps the camelCase properties of a plain object to snake_case wire keys', function () {
    $source = (object) ['id' => 2, 'title' => 'From Object', 'authorEmail' => 'object@example.com'];

    expect(TestPostData::from($source)->authorEmail)->toBe('object@example.com');
});

test('it auto-casts nested child DTOs', function () {
    $dto = TestPostData::from([
        'id' => 3,
        'title' => 'Nested Test',
        'author_email' => 'nested@example.com',
        'author' => [
            'id' => 99,
            'name' => 'John Doe',
        ],
    ]);

    expect($dto->author)->toBeInstanceOf(TestAuthorData::class)
        ->and($dto->author->id)->toBe(99)
        ->and($dto->author->name)->toBe('John Doe');
});

test('it casts each item of an array property declared with #[Collection]', function () {
    $dto = TestAuthorListData::from([
        'authors' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']],
    ]);

    expect($dto->authors)->toHaveCount(2)
        ->and($dto->authors[1])->toBeInstanceOf(TestAuthorData::class)
        ->and($dto->authors[1]->name)->toBe('Bob');
});

test('it throws an exception when a required property is missing and has no default', function () {
    TestPostData::from([
        'id' => 4,
        'title' => 'Missing Email',
    ]);
})->throws(InvalidArgumentException::class, 'Missing required property [authorEmail]');

test('it creates a DTO from an Eloquent model', function () {
    $model = new TestPostModel([
        'id' => 10,
        'title' => 'Eloquent Model Post',
        'author_email' => 'model@example.com',
    ]);

    $dto = TestPostData::from($model);

    expect($dto->id)->toBe(10)
        ->and($dto->title)->toBe('Eloquent Model Post')
        ->and($dto->authorEmail)->toBe('model@example.com');
});

test('it throws a clear error when a model hides an attribute the DTO requires', function () {
    TestSecretData::from(new TestSecretModel(['name' => 'Visible', 'secret' => 'hidden-value']));
})->throws(InvalidArgumentException::class, 'Missing required property [secret] for ['.TestSecretData::class.'].');

test('it returns self if already an instance of static', function () {
    $original = TestPostData::from([
        'id' => 1,
        'title' => 'Existing',
        'author_email' => 'existing@example.com',
    ]);

    expect(TestPostData::from($original))->toBe($original);
});

test('it collects an iterable of items into a Collection of DTOs', function () {
    $collection = TestAuthorData::collect([
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ]);

    expect($collection)->toHaveCount(2)
        ->and($collection[0])->toBeInstanceOf(TestAuthorData::class)
        ->and($collection[0]->name)->toBe('Alice')
        ->and($collection[1]->name)->toBe('Bob');
});

test('it recursively serializes to a snake_case array including nested DTOs and Enums', function () {
    $dto = TestPostData::from([
        'id' => 1,
        'title' => 'Array Serialization',
        'author_email' => 'author@example.com',
        'author' => ['id' => 50, 'name' => 'Jane'],
        'status' => TestStatus::Active,
        'tags' => ['laravel', 'modular'],
    ]);

    expect($dto->toArray())->toBe([
        'id' => 1,
        'title' => 'Array Serialization',
        'author_email' => 'author@example.com',
        'author' => [
            'id' => 50,
            'name' => 'Jane',
        ],
        'status' => 'active',
        'tags' => ['laravel', 'modular'],
    ]);
});

test('it serializes to JSON properly for Inertia responses', function () {
    $dto = TestAuthorData::from(['id' => 7, 'name' => 'Sam']);

    expect(json_encode($dto))->toBe('{"id":7,"name":"Sam"}')
        ->and($dto->toJson())->toBe('{"id":7,"name":"Sam"}');
});

test('it throws when an enum value is invalid instead of silently becoming null', function () {
    TestPostData::from([
        'id' => 1,
        'title' => 'Bad Status',
        'author_email' => 'a@example.com',
        'status' => 'archived',
    ]);
})->throws(ValueError::class);

test('it ignores a camelCase key in an array and reports the property as missing', function () {
    TestPostData::from([
        'id' => 1,
        'title' => 'CamelCase Key',
        'authorEmail' => 'camel@example.com',
    ]);
})->throws(InvalidArgumentException::class, 'Missing required property [authorEmail]');
